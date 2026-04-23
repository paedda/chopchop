package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"
)

var codeRe = regexp.MustCompile(`^[a-zA-Z0-9\-]{3,20}$`)

type handler struct {
	db *pgxpool.Pool
}

// GET /health
func (h *handler) health(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, orderedMap{
		{"status", "ok"},
		{"language", "go"},
		{"framework", "net/http"},
	})
}

// POST /chop
func (h *handler) chop(w http.ResponseWriter, r *http.Request) {
	var body struct {
		URL        *string `json:"url"`
		CustomCode *string `json:"custom_code"`
		ExpiresIn  *int    `json:"expires_in"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil || body.URL == nil {
		writeError(w, http.StatusBadRequest, "Invalid or missing URL")
		return
	}

	if !isValidURL(*body.URL) {
		writeError(w, http.StatusBadRequest, "Invalid or missing URL")
		return
	}

	var code string
	if body.CustomCode != nil {
		if !codeRe.MatchString(*body.CustomCode) {
			writeError(w, http.StatusBadRequest, "custom_code must be 3–20 alphanumeric characters or hyphens")
			return
		}
		var exists bool
		err := h.db.QueryRow(context.Background(),
			"SELECT EXISTS(SELECT 1 FROM links WHERE code = $1)", *body.CustomCode).Scan(&exists)
		if err != nil {
			writeError(w, http.StatusInternalServerError, "Internal server error")
			return
		}
		if exists {
			writeError(w, http.StatusConflict, "Custom code already taken")
			return
		}
		code = *body.CustomCode
	} else {
		var err error
		code, err = generateCode(context.Background(), h.db)
		if err != nil {
			writeError(w, http.StatusInternalServerError, "Failed to generate a unique code")
			return
		}
	}

	var expiresAt *time.Time
	if body.ExpiresIn != nil {
		if *body.ExpiresIn <= 0 || *body.ExpiresIn > 2_592_000 {
			writeError(w, http.StatusBadRequest, "expires_in must be a positive integer no greater than 2592000")
			return
		}
		t := time.Now().UTC().Add(time.Duration(*body.ExpiresIn) * time.Second)
		expiresAt = &t
	}

	now := time.Now().UTC()
	var (
		id          int64
		createdAt   time.Time
		dbExpiresAt *time.Time
	)
	err := h.db.QueryRow(context.Background(),
		`INSERT INTO links (code, url, created_at, expires_at)
		 VALUES ($1, $2, $3, $4)
		 RETURNING id, created_at, expires_at`,
		code, *body.URL, now, expiresAt,
	).Scan(&id, &createdAt, &dbExpiresAt)
	if err != nil {
		writeError(w, http.StatusInternalServerError, "Internal server error")
		return
	}

	scheme := "http"
	if r.TLS != nil {
		scheme = "https"
	}
	host := r.Host

	writeJSON(w, http.StatusCreated, orderedMap{
		{"code", code},
		{"short_url", scheme + "://" + host + "/" + code},
		{"url", *body.URL},
		{"created_at", formatTime(createdAt)},
		{"expires_at", formatTimePtr(dbExpiresAt)},
	})
}

// GET /stats/{code}
func (h *handler) stats(w http.ResponseWriter, r *http.Request) {
	code := r.PathValue("code")

	var (
		id        int64
		linkURL   string
		createdAt time.Time
		expiresAt *time.Time
	)
	err := h.db.QueryRow(context.Background(),
		"SELECT id, url, created_at, expires_at FROM links WHERE code = $1", code,
	).Scan(&id, &linkURL, &createdAt, &expiresAt)
	if err != nil {
		writeError(w, http.StatusNotFound, "Link not found")
		return
	}

	var totalClicks int64
	h.db.QueryRow(context.Background(),
		"SELECT COUNT(*) FROM clicks WHERE link_id = $1", id,
	).Scan(&totalClicks)

	rows, _ := h.db.Query(context.Background(),
		`SELECT clicked_at, referer, user_agent FROM clicks
		 WHERE link_id = $1 ORDER BY clicked_at DESC LIMIT 10`, id)
	defer rows.Close()

	type clickEntry struct {
		ClickedAt string  `json:"clicked_at"`
		Referer   *string `json:"referer"`
		UserAgent *string `json:"user_agent"`
	}
	recent := []clickEntry{}
	for rows.Next() {
		var clickedAt time.Time
		var referer, userAgent *string
		rows.Scan(&clickedAt, &referer, &userAgent)
		recent = append(recent, clickEntry{
			ClickedAt: formatTime(clickedAt),
			Referer:   referer,
			UserAgent: userAgent,
		})
	}

	writeJSON(w, http.StatusOK, orderedMap{
		{"code", code},
		{"url", linkURL},
		{"created_at", formatTime(createdAt)},
		{"expires_at", formatTimePtr(expiresAt)},
		{"total_clicks", totalClicks},
		{"recent_clicks", recent},
	})
}

// GET /{code}
func (h *handler) resolve(w http.ResponseWriter, r *http.Request) {
	code := r.PathValue("code")

	var (
		id        int64
		linkURL   string
		expiresAt *time.Time
	)
	err := h.db.QueryRow(context.Background(),
		"SELECT id, url, expires_at FROM links WHERE code = $1", code,
	).Scan(&id, &linkURL, &expiresAt)
	if err != nil {
		writeError(w, http.StatusNotFound, "Link not found")
		return
	}

	if expiresAt != nil && time.Now().UTC().After(*expiresAt) {
		writeError(w, http.StatusGone, "Link has expired")
		return
	}

	ip := clientIP(r)
	userAgent := r.Header.Get("User-Agent")
	referer := r.Header.Get("Referer")

	h.db.Exec(context.Background(),
		`INSERT INTO clicks (link_id, clicked_at, ip_address, user_agent, referer)
		 VALUES ($1, $2, $3, $4, $5)`,
		id, time.Now().UTC(),
		nullableString(ip), nullableString(userAgent), nullableString(referer),
	)

	http.Redirect(w, r, linkURL, http.StatusMovedPermanently)
}

// ── helpers ───────────────────────────────────────────────────────────────────

func isValidURL(raw string) bool {
	if !strings.HasPrefix(raw, "http://") && !strings.HasPrefix(raw, "https://") {
		return false
	}
	u, err := url.Parse(raw)
	if err != nil || u.Host == "" {
		return false
	}
	host := u.Hostname()
	return strings.Contains(host, ".")
}

func clientIP(r *http.Request) string {
	if xff := r.Header.Get("X-Forwarded-For"); xff != "" {
		return strings.SplitN(xff, ",", 2)[0]
	}
	// Strip port from RemoteAddr
	addr := r.RemoteAddr
	if i := strings.LastIndex(addr, ":"); i != -1 {
		addr = addr[:i]
	}
	return strings.Trim(addr, "[]")
}

func nullableString(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

func formatTime(t time.Time) string {
	return t.UTC().Format("2006-01-02T15:04:05+00:00")
}

func formatTimePtr(t *time.Time) *string {
	if t == nil {
		return nil
	}
	s := formatTime(*t)
	return &s
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(v)
}

func writeError(w http.ResponseWriter, status int, msg string) {
	writeJSON(w, status, map[string]string{"error": msg})
}

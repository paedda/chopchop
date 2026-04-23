package main

import (
	"context"
	"log"
	"net/http"
	"os"

	"github.com/jackc/pgx/v5/pgxpool"
)

func main() {
	databaseURL := os.Getenv("DATABASE_URL")
	if databaseURL == "" {
		log.Fatal("DATABASE_URL environment variable is required")
	}

	pool, err := pgxpool.New(context.Background(), databaseURL)
	if err != nil {
		log.Fatalf("unable to connect to database: %v", err)
	}
	defer pool.Close()

	h := &handler{db: pool}

	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", h.health)
	mux.HandleFunc("POST /chop", h.chop)
	mux.HandleFunc("GET /stats/{code}", h.stats)
	mux.HandleFunc("GET /{code}", h.resolve)

	log.Println("ChopChop Go/net/http listening on :8000")
	if err := http.ListenAndServe(":8000", mux); err != nil {
		log.Fatalf("server error: %v", err)
	}
}

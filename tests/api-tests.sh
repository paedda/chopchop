#!/usr/bin/env bash
# Usage: ./tests/api-tests.sh [PORT]
# Default port: 8001 (php-symfony)

set -euo pipefail

PORT="${1:-8001}"
BASE="http://localhost:${PORT}"
PASS=0
FAIL=0

green='\033[0;32m'
red='\033[0;31m'
reset='\033[0m'

check() {
    local name="$1" expected="$2" actual="$3" body="$4"
    if [ "$actual" = "$expected" ]; then
        echo -e "${green}PASS${reset}: $name"
        ((PASS++)) || true
    else
        echo -e "${red}FAIL${reset}: $name"
        echo "  expected HTTP $expected, got HTTP $actual"
        echo "  body: $body"
        ((FAIL++)) || true
    fi
}

# ── health check ──────────────────────────────────────────────────────────────

resp=$(curl -s -o /tmp/cc_body -w "%{http_code}" "$BASE/health")
body=$(cat /tmp/cc_body)
check "GET /health returns 200" 200 "$resp" "$body"
echo "  $body"

# ── shorten a URL ─────────────────────────────────────────────────────────────

resp=$(curl -s -o /tmp/cc_body -w "%{http_code}" -X POST "$BASE/shorten" \
    -H "Content-Type: application/json" \
    -d '{"url":"https://example.com/a/very/long/path"}')
body=$(cat /tmp/cc_body)
check "POST /shorten returns 201" 201 "$resp" "$body"

# Extract the generated code
CODE=$(echo "$body" | grep -o '"code":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "  generated code: $CODE"

# ── redirect ──────────────────────────────────────────────────────────────────

resp=$(curl -s -o /tmp/cc_body -w "%{http_code}" --max-redirs 0 "$BASE/$CODE")
body=$(cat /tmp/cc_body)
check "GET /:code redirects (301)" 301 "$resp" "$body"

resp=$(curl -s -o /tmp/cc_body -w "%{http_code}" -L "$BASE/$CODE")
check "GET /:code follows redirect (200)" 200 "$resp" ""

# ── stats increment after redirect ────────────────────────────────────────────

resp=$(curl -s -o /tmp/cc_body -w "%{http_code}" "$BASE/stats/$CODE")
body=$(cat /tmp/cc_body)
check "GET /stats/:code returns 200" 200 "$resp" "$body"

CLICKS=$(echo "$body" | grep -o '"total_clicks":[0-9]*' | cut -d: -f2)
if [ "$CLICKS" -ge 1 ]; then
    echo -e "${green}PASS${reset}: total_clicks >= 1 (got $CLICKS)"
    ((PASS++)) || true
else
    echo -e "${red}FAIL${reset}: total_clicks should be >= 1, got $CLICKS"
    ((FAIL++)) || true
fi

# ── custom code ───────────────────────────────────────────────────────────────

CUSTOM="testcode$RANDOM"
resp=$(curl -s -o /tmp/cc_body -w "%{http_code}" -X POST "$BASE/shorten" \
    -H "Content-Type: application/json" \
    -d "{\"url\":\"https://example.com\",\"custom_code\":\"$CUSTOM\"}")
body=$(cat /tmp/cc_body)
check "POST /shorten with custom_code returns 201" 201 "$resp" "$body"

# ── duplicate custom code → 409 ───────────────────────────────────────────────

resp=$(curl -s -o /tmp/cc_body -w "%{http_code}" -X POST "$BASE/shorten" \
    -H "Content-Type: application/json" \
    -d "{\"url\":\"https://example.com\",\"custom_code\":\"$CUSTOM\"}")
body=$(cat /tmp/cc_body)
check "POST /shorten duplicate custom_code returns 409" 409 "$resp" "$body"

# ── invalid URL → 400 ─────────────────────────────────────────────────────────

resp=$(curl -s -o /tmp/cc_body -w "%{http_code}" -X POST "$BASE/shorten" \
    -H "Content-Type: application/json" \
    -d '{"url":"not-a-url"}')
body=$(cat /tmp/cc_body)
check "POST /shorten invalid URL returns 400" 400 "$resp" "$body"

# ── expired link → 410 ────────────────────────────────────────────────────────

resp=$(curl -s -o /tmp/cc_body -w "%{http_code}" -X POST "$BASE/shorten" \
    -H "Content-Type: application/json" \
    -d '{"url":"https://example.com","expires_in":1}')
body=$(cat /tmp/cc_body)
check "POST /shorten with expires_in returns 201" 201 "$resp" "$body"

EXP_CODE=$(echo "$body" | grep -o '"code":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "  waiting 2s for link to expire..."
sleep 2

resp=$(curl -s -o /tmp/cc_body -w "%{http_code}" --max-redirs 0 "$BASE/$EXP_CODE")
body=$(cat /tmp/cc_body)
check "GET /:code on expired link returns 410" 410 "$resp" "$body"

# ── 404 for unknown code ──────────────────────────────────────────────────────

resp=$(curl -s -o /tmp/cc_body -w "%{http_code}" "$BASE/doesnotexist999")
body=$(cat /tmp/cc_body)
check "GET /:code unknown returns 404" 404 "$resp" "$body"

# ── summary ───────────────────────────────────────────────────────────────────

echo ""
echo "Results: ${PASS} passed, ${FAIL} failed (port ${PORT})"
[ "$FAIL" -eq 0 ] && exit 0 || exit 1

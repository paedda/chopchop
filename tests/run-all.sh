#!/usr/bin/env bash
# Run the API test suite against all backends and print a summary.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

PORTS=(8001 8002 8003 8004 8005 8006 8007 8008)
NAMES=("PHP/Symfony" "Python/FastAPI" "TypeScript/Express" "Elixir/Phoenix" "Java/Spring Boot" "Go/net/http" "Ruby/Sinatra" "C#/ASP.NET Core")

green='\033[0;32m'
red='\033[0;31m'
bold='\033[1m'
reset='\033[0m'

OVERALL_PASS=0
OVERALL_FAIL=0
FAILED_BACKENDS=()

for i in "${!PORTS[@]}"; do
  PORT="${PORTS[$i]}"
  NAME="${NAMES[$i]}"
  echo -e "\n${bold}── $NAME (port $PORT) ──────────────────────────────${reset}"

  if ! curl -s --max-time 2 "http://localhost:$PORT/health" > /dev/null 2>&1; then
    echo -e "${red}SKIP${reset}: service not reachable on port $PORT"
    FAILED_BACKENDS+=("$NAME")
    continue
  fi

  if output=$("$SCRIPT_DIR/api-tests.sh" "$PORT" 2>&1); then
    echo "$output"
    PASS=$(echo "$output" | grep -o '[0-9]* passed' | grep -o '[0-9]*')
    OVERALL_PASS=$((OVERALL_PASS + PASS))
  else
    echo "$output"
    FAIL=$(echo "$output" | grep -o '[0-9]* failed' | grep -o '[0-9]*' | tail -1)
    PASS=$(echo "$output" | grep -o '[0-9]* passed' | grep -o '[0-9]*' | tail -1)
    OVERALL_PASS=$((OVERALL_PASS + ${PASS:-0}))
    OVERALL_FAIL=$((OVERALL_FAIL + ${FAIL:-0}))
    FAILED_BACKENDS+=("$NAME")
  fi
done

echo ""
echo -e "${bold}══════════════════════════════════════════════════${reset}"
echo -e "${bold}Overall: ${OVERALL_PASS} passed, ${OVERALL_FAIL} failed across ${#PORTS[@]} backends${reset}"

if [ ${#FAILED_BACKENDS[@]} -eq 0 ]; then
  echo -e "${green}All backends passed.${reset}"
  exit 0
else
  echo -e "${red}Failed: ${FAILED_BACKENDS[*]}${reset}"
  exit 1
fi

#!/usr/bin/env bash
#
# serve.sh — Startet den Produkt-Viewer Webserver
#
# Verwendung:
#   ./scripts/serve.sh [PORT]
#
# Optionen:
#   PORT    Port-Nummer (Standard: 8000)
#
# Der Server ist erreichbar unter http://localhost:PORT
# Beenden mit Ctrl+C

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

PORT="${1:-8000}"

GREEN='\033[32m'
BLUE='\033[34m'
RESET='\033[0m'

echo -e "${GREEN}[serve]${RESET} Starte Produkt-Viewer auf http://localhost:$PORT"
echo -e "${BLUE}[serve]${RESET} Beenden mit Ctrl+C"
echo ""

php -S "localhost:$PORT" -t "$PROJECT_DIR/server" "$PROJECT_DIR/server/server.php"

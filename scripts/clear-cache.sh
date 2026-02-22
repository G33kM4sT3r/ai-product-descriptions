#!/usr/bin/env bash
#
# clear-cache.sh — Leert den Generierungs-Cache
#
# Verwendung:
#   ./scripts/clear-cache.sh [--yes]
#
# Optionen:
#   --yes    Bestätigung überspringen

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
CACHE_FILE="$PROJECT_DIR/generation/generation_cache.json"

YELLOW='\033[33m'
GREEN='\033[32m'
RESET='\033[0m'

if [[ ! -f "$CACHE_FILE" ]]; then
    echo -e "${YELLOW}[cache]${RESET} Kein Cache vorhanden: $CACHE_FILE"
    exit 0
fi

# Cache-Statistik anzeigen
ENTRIES=$(php -r "\$d = json_decode(file_get_contents('$CACHE_FILE'), true); echo is_array(\$d) ? count(\$d) : 0;")
echo -e "${YELLOW}[cache]${RESET} Cache enthält $ENTRIES Produkte"

if [[ "${1:-}" != "--yes" ]]; then
    read -rp "Cache wirklich leeren? (j/N) " CONFIRM
    if [[ "$CONFIRM" != "j" && "$CONFIRM" != "J" ]]; then
        echo "Abgebrochen."
        exit 0
    fi
fi

rm "$CACHE_FILE"
echo -e "${GREEN}[cache]${RESET} Cache geleert"

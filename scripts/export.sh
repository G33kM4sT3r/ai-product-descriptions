#!/usr/bin/env bash
#
# export.sh — Exportiert Produktbeschreibungen als Markdown-Dateien
#
# Verwendung:
#   ./scripts/export.sh
#
# Voraussetzungen:
#   - PHP 8.5+
#   - products_with_descriptions.json vorhanden (nach generate.sh)
#   - generation/gen-markdown.conf.php vorhanden

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

BLUE='\033[34m'
GREEN='\033[32m'
RED='\033[31m'
RESET='\033[0m'

log() { echo -e "${BLUE}[export]${RESET} $1"; }

INPUT_FILE="$PROJECT_DIR/products_with_descriptions.json"
if [[ ! -f "$INPUT_FILE" ]]; then
    echo -e "${RED}[export] FEHLER:${RESET} $INPUT_FILE nicht gefunden." >&2
    echo -e "${RED}[export]${RESET} Zuerst ./scripts/generate.sh ausführen." >&2
    exit 1
fi

CONFIG_FILE="$PROJECT_DIR/generation/gen-markdown.conf.php"
if [[ ! -f "$CONFIG_FILE" ]]; then
    echo -e "${RED}[export] FEHLER:${RESET} $CONFIG_FILE nicht gefunden." >&2
    echo -e "${RED}[export]${RESET} Kopiere: cp generation/gen-markdown.conf.example.php generation/gen-markdown.conf.php" >&2
    exit 1
fi

log "Starte Markdown-Export..."
echo ""

php "$PROJECT_DIR/generation/gen-markdown.php"

echo ""
echo -e "${GREEN}[export]${RESET} Export abgeschlossen"

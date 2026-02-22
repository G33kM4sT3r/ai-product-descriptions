#!/usr/bin/env bash
#
# generate.sh — Führt die KI-Pipeline zur Produktbeschreibungs-Generierung aus
#
# Verwendung:
#   ./scripts/generate.sh [--force]
#
# Optionen:
#   --force    Cache leeren und alle Produkte neu generieren
#
# Voraussetzungen:
#   - PHP 8.5+
#   - LM Studio (oder konfigurierter API-Provider) erreichbar
#   - generation/gen-desc.conf.php vorhanden
#
# Ausgabe:
#   - Terminal: Farbige Fortschrittsanzeige
#   - Logdatei: logs/generate-YYYY-MM-DD-HHMMSS.log

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="$PROJECT_DIR/logs"
TIMESTAMP=$(date +"%Y-%m-%d-%H%M%S")
LOG_FILE="$LOG_DIR/generate-$TIMESTAMP.log"

# Farben
RED='\033[31m'
GREEN='\033[32m'
YELLOW='\033[33m'
BLUE='\033[34m'
RESET='\033[0m'

log() { echo -e "${BLUE}[generate]${RESET} $1"; }
error() { echo -e "${RED}[generate] FEHLER:${RESET} $1" >&2; }
success() { echo -e "${GREEN}[generate]${RESET} $1"; }
warn() { echo -e "${YELLOW}[generate]${RESET} $1"; }

# Cache leeren bei --force
if [[ "${1:-}" == "--force" ]]; then
    CACHE_FILE="$PROJECT_DIR/generation/generation_cache.json"
    if [[ -f "$CACHE_FILE" ]]; then
        rm "$CACHE_FILE"
        warn "Cache geleert: $CACHE_FILE"
    fi
fi

# Log-Verzeichnis erstellen
mkdir -p "$LOG_DIR"

log "Starte Generierung..."
log "Log-Datei: $LOG_FILE"
echo ""

# Pipeline ausführen mit tee für parallele Log-Ausgabe
START_TIME=$(date +%s)

set +e
php "$PROJECT_DIR/generation/gen-desc.php" 2>&1 | tee "$LOG_FILE"
EXIT_CODE="${PIPESTATUS[0]}"
set -e

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
MINUTES=$((DURATION / 60))
SECONDS=$((DURATION % 60))

echo ""
if [[ "$EXIT_CODE" -eq 0 ]]; then
    success "Generierung abgeschlossen in ${MINUTES}m ${SECONDS}s"
else
    error "Generierung fehlgeschlagen (Exit-Code: $EXIT_CODE)"
fi

log "Log gespeichert: $LOG_FILE"
exit "$EXIT_CODE"

#!/usr/bin/env bash
#
# run.sh — Hauptskript: Führt die komplette Pipeline aus
#
# Verwendung:
#   ./scripts/run.sh [--force] [--serve]
#
# Optionen:
#   --force    Cache leeren und alle Produkte neu generieren
#   --serve    Nach Abschluss den Web-Viewer starten
#
# Ablauf:
#   1. Pre-Flight Checks (PHP, API, Dateien)
#   2. Produktbeschreibungen generieren
#   3. Markdown-Dateien exportieren
#   4. Statistiken anzeigen
#   5. Optional: Web-Viewer starten

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# Farben
RED='\033[31m'
GREEN='\033[32m'
YELLOW='\033[33m'
BLUE='\033[34m'
BOLD='\033[1m'
RESET='\033[0m'

log() { echo -e "${BLUE}[run]${RESET} $1"; }
error() { echo -e "${RED}[run] FEHLER:${RESET} $1" >&2; }
success() { echo -e "${GREEN}[run]${RESET} $1"; }
warn() { echo -e "${YELLOW}[run]${RESET} $1"; }

# Argumente parsen
FORCE=false
SERVE=false
for arg in "$@"; do
    case "$arg" in
        --force) FORCE=true ;;
        --serve) SERVE=true ;;
        *)
            error "Unbekannte Option: $arg"
            echo "Verwendung: $0 [--force] [--serve]"
            exit 1
            ;;
    esac
done

echo -e "${BOLD}${BLUE}"
echo "======================================================"
echo "  AI Produktbeschreibungs-Pipeline"
echo "======================================================"
echo -e "${RESET}"

# ── Pre-Flight Checks ──────────────────────────────────────────────────────

log "Pre-Flight Checks..."

# PHP Version prüfen
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;" 2>/dev/null || echo "")
if [[ -z "$PHP_VERSION" ]]; then
    error "PHP nicht gefunden. Bitte PHP 8.5+ installieren."
    exit 1
fi
PHP_MAJOR=$(echo "$PHP_VERSION" | cut -d. -f1)
PHP_MINOR=$(echo "$PHP_VERSION" | cut -d. -f2)
if [[ "$PHP_MAJOR" -lt 8 ]] || [[ "$PHP_MAJOR" -eq 8 && "$PHP_MINOR" -lt 5 ]]; then
    error "PHP $PHP_VERSION gefunden, aber PHP 8.5+ benötigt."
    exit 1
fi
log "  PHP $PHP_VERSION"

# Config-Dateien prüfen
if [[ ! -f "$PROJECT_DIR/generation/gen-desc.conf.php" ]]; then
    error "generation/gen-desc.conf.php nicht gefunden."
    warn "  Kopiere: cp generation/gen-desc.conf.example.php generation/gen-desc.conf.php"
    exit 1
fi
log "  gen-desc.conf.php"

if [[ ! -f "$PROJECT_DIR/generation/gen-markdown.conf.php" ]]; then
    error "generation/gen-markdown.conf.php nicht gefunden."
    warn "  Kopiere: cp generation/gen-markdown.conf.example.php generation/gen-markdown.conf.php"
    exit 1
fi
log "  gen-markdown.conf.php"

# Input-Datei prüfen
if [[ ! -f "$PROJECT_DIR/test-products.json" ]]; then
    error "test-products.json nicht gefunden."
    exit 1
fi
PRODUCT_COUNT=$(php -r "\$d = json_decode(file_get_contents('$PROJECT_DIR/test-products.json'), true); echo is_array(\$d) ? count(\$d) : 0;")
log "  test-products.json ($PRODUCT_COUNT Produkte)"

# API-Erreichbarkeit prüfen (nur für lokale APIs)
API_URL=$(php -r "
    require_once '$PROJECT_DIR/generation/inc.php';
    require_once '$PROJECT_DIR/generation/gen-desc.conf.php';
    echo \$config['url'] ?? '';
" 2>/dev/null || echo "")

if [[ -n "$API_URL" ]]; then
    if [[ "$API_URL" == *"localhost"* ]] || [[ "$API_URL" == *"127.0.0.1"* ]]; then
        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 3 "$API_URL" 2>/dev/null || echo "000")
        if [[ "$HTTP_CODE" == "000" ]]; then
            error "API nicht erreichbar: $API_URL"
            warn "  Ist LM Studio gestartet?"
            exit 1
        fi
        log "  API erreichbar ($API_URL)"
    else
        log "  Cloud-API: $API_URL"
    fi
fi

echo ""

# ── Generierung ─────────────────────────────────────────────────────────────

GENERATE_ARGS=()
if [[ "$FORCE" == true ]]; then
    GENERATE_ARGS+=("--force")
fi

bash "$SCRIPT_DIR/generate.sh" "${GENERATE_ARGS[@]+"${GENERATE_ARGS[@]}"}"
echo ""

# ── Export ───────────────────────────────────────────────────────────────────

bash "$SCRIPT_DIR/export.sh"
echo ""

# ── Statistiken ─────────────────────────────────────────────────────────────

bash "$SCRIPT_DIR/show-stats.sh"
echo ""

success "Pipeline abgeschlossen!"

# ── Optional: Viewer starten ────────────────────────────────────────────────

if [[ "$SERVE" == true ]]; then
    echo ""
    bash "$SCRIPT_DIR/serve.sh"
fi

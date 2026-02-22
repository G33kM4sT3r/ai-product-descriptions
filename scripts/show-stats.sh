#!/usr/bin/env bash
#
# show-stats.sh — Zeigt Statistiken der letzten Generierung
#
# Verwendung:
#   ./scripts/show-stats.sh
#
# Liest die neueste Log-Datei und products_with_descriptions.json aus

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="$PROJECT_DIR/logs"
OUTPUT_FILE="$PROJECT_DIR/products_with_descriptions.json"

BLUE='\033[34m'
GREEN='\033[32m'
YELLOW='\033[33m'
RESET='\033[0m'

echo -e "${BLUE}=== Generierungs-Statistiken ===${RESET}"
echo ""

# Neueste Log-Datei anzeigen
if [[ -d "$LOG_DIR" ]]; then
    LATEST_LOG=$(find "$LOG_DIR" -name "generate-*.log" -type f 2>/dev/null | sort -r | head -1)
    if [[ -n "${LATEST_LOG:-}" ]]; then
        echo -e "${GREEN}Letzter Lauf:${RESET} $(basename "$LATEST_LOG")"
        # Zusammenfassung aus Log extrahieren
        if grep -q "ZUSAMMENFASSUNG" "$LATEST_LOG" 2>/dev/null; then
            grep -A 5 "ZUSAMMENFASSUNG" "$LATEST_LOG" | tail -n +2
        fi
        echo ""
    fi
fi

# Produkt-Statistiken aus JSON
if [[ -f "$OUTPUT_FILE" ]]; then
    php -r "
        \$data = json_decode(file_get_contents('$OUTPUT_FILE'), true) ?? [];
        \$count = count(\$data);
        echo \"Produkte gesamt: \$count\n\";
        if (\$count > 0) {
            \$lengths = array_map(fn(\$p) => mb_strlen(\$p['beschreibung'] ?? ''), \$data);
            \$min = min(\$lengths);
            \$max = max(\$lengths);
            \$avg = round(array_sum(\$lengths) / \$count);
            echo \"Zeichenlänge: min=\$min max=\$max avg=\$avg\n\";
            \$inRange = count(array_filter(\$lengths, fn(\$l) => \$l >= 650 && \$l <= 850));
            \$pct = round(\$inRange / \$count * 100);
            echo \"Im Zielbereich (650-850): \$inRange/\$count (\$pct%)\n\";
        }
    "
else
    echo -e "${YELLOW}Keine Ergebnisdatei vorhanden${RESET}"
fi

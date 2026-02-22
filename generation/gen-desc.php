<?php

declare(strict_types=1);

/**
 * Produktbeschreibungs-Generator
 *
 * 5-stufige KI-Pipeline zur Generierung SEO-optimierter deutscher
 * Produktbeschreibungen für Baumarkt-Onlineshops.
 *
 * Unterstützt LM Studio (lokal), OpenAI und Anthropic APIs.
 * Konfigurierbar pro Stage (Modell, Temperatur, Token-Limit).
 *
 * Verwendung:
 *   php generation/gen-desc.php
 *
 * Voraussetzungen:
 *   - generation/gen-desc.conf.php vorhanden
 *   - API-Provider erreichbar (LM Studio, OpenAI, Anthropic)
 */

require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/gen-desc.conf.php';

// Provider-Fähigkeiten automatisch erkennen
resolveProviderCapabilities($config);

// Datei-Logging initialisieren (falls konfiguriert)
if (!empty($config['logFile'])) {
    logMsg("Log-Datei: {$config['logFile']}", 'INFO', $config['logFile']);
}

if (!file_exists($config['inputFile'])) {
    throw new RuntimeException('Input JSON nicht gefunden: ' . $config['inputFile']);
}

// ============================================================================
// FAKTENVALIDIERUNG
// ============================================================================

/**
 * Validiert ob kritische Fakten aus den Originaldaten im generierten Text vorkommen
 *
 * Prüft Wert UND Einheit gemeinsam (z.B. "18 V" statt nur "18"), um
 * Falsch-Positive zu vermeiden. Unterstützt kategorie-spezifische kritische Keys
 * die über categoryCriticalKeys in der Config definiert werden.
 *
 * @param string $text    Der generierte Text
 * @param array  $product Die Original-Produktdaten
 * @param array  $config  Konfiguration mit criticalKeys und categoryCriticalKeys
 * @return array          ['valid' => bool, 'found' => string[], 'missing' => string[]]
 */
function validateFactsInText(string $text, array $product, array $config): array
{
    $missingFacts = [];
    $foundFacts = [];

    // Kategorie-spezifische Keys mit globalen Keys zusammenführen
    $kategorie = $product['produktkategorie'] ?? '';
    $criticalKeys = $config['criticalKeys'] ?? [];
    $categoryKeys = $config['categoryCriticalKeys'][$kategorie] ?? [];
    $allKeys = array_unique(array_merge($criticalKeys, $categoryKeys));

    foreach ($product['technische_spezifikationen'] ?? [] as $spec) {
        if (!in_array($spec['key'], $allKeys, true)) {
            continue;
        }

        $value = trim($spec['value']);
        if (empty($value)) {
            continue;
        }

        // Vollständigen Wert suchen (z.B. "18 V")
        $found = mb_stripos($text, $value) !== false;

        // Fallback: Zahl + Einheit mit optionalem Leerzeichen (z.B. "18V" oder "18 V")
        if (!$found && preg_match('/^([\d,.]+)\s*(.+)$/', $value, $parts)) {
            $numericPart = $parts[1];
            $unitPart = trim($parts[2]);
            $pattern = '/' . preg_quote($numericPart, '/') . '\s*' . preg_quote($unitPart, '/') . '/i';
            $found = (bool) preg_match($pattern, $text);
        }

        if ($found) {
            $foundFacts[] = "{$spec['key']}: {$spec['value']}";
        } else {
            $missingFacts[] = "{$spec['key']}: {$spec['value']}";
        }
    }

    return [
        'valid'   => count($missingFacts) <= 2,
        'found'   => $foundFacts,
        'missing' => $missingFacts,
    ];
}

// ============================================================================
// HAUPTPROGRAMM
// ============================================================================

// JSON laden
$products = json_decode(file_get_contents($config['inputFile']), true, 512, JSON_THROW_ON_ERROR);

// Duplikat-Prüfung für Artikelnummern
$artikelnummern = array_column($products, 'artikelnummer');
$duplikate = array_diff_assoc($artikelnummern, array_unique($artikelnummern));
if (!empty($duplikate)) {
    logMsg("Warnung: Doppelte Artikelnummern: " . implode(', ', array_unique($duplikate)), 'WARNING');
}

// Cache laden (für Fortsetzung nach Abbruch)
$cache = loadCache($config['cacheFile']);

$output = [];

$stats = [
    'processed'   => 0,
    'skipped'     => 0,
    'errors'      => 0,
    'totalTokens' => 0,
    'startTime'   => microtime(true),
];

$stageNames = [
    1 => 'Faktenextraktion',
    2 => 'Nutzenargumentation',
    3 => 'SEO-Optimierung',
    4 => 'Qualitätskontrolle',
    5 => 'Kurztexte',
];

$totalProducts = count($products);

logMsg("Starte Generierung...");
logMsg("Provider: {$config['provider']} | Modell: {$config['model']}");
logMsg("Produkte: $totalProducts | Cache: " . count($cache) . " vorhanden");
logMsg("System-Rolle: " . ($config['supportsSystemRole'] ? 'ja' : 'nein (eingebettet)')
    . " | JSON-Schema: " . ($config['supportsJsonSchema'] ? 'ja' : 'nein'));
logMsg(str_repeat("\xe2\x94\x80", 70));

// ============================================================================
// PIPELINE-SCHLEIFE
// ============================================================================

foreach ($products as $index => $product) {
    $artikelnummer = $product['artikelnummer'];
    $produktName = $product['produktbezeichnung'];
    $position = ($index + 1) . "/$totalProducts";

    // Cache-Check mit Hash-Validierung
    // Hash ändert sich wenn Produktdaten modifiziert werden → automatische Neugenerierung
    $inputHash = md5(json_encode($product, JSON_THROW_ON_ERROR));
    if (isset($cache[$artikelnummer])) {
        $cachedHash = $cache[$artikelnummer]['_inputHash'] ?? null;
        if ($cachedHash === $inputHash) {
            $output[] = $cache[$artikelnummer];
            $stats['skipped']++;
            logMsg("[$position] $artikelnummer übersprungen (Cache)");
            continue;
        }
        logMsg("[$position] $artikelnummer — Produktdaten geändert, generiere neu", 'WARNING');
    }

    try {
        $productStartTime = microtime(true);
        $productContext = formatProductContext($product);
        $productTokens = 0;

        logMsg("[$position] $artikelnummer $produktName");

        // ── Stage 1: Faktenextraktion ──────────────────────────────────
        $stageStart = microtime(true);
        $prompt1 = loadPrompt('gen-desc.1.fact-extraction', [
            'productContext' => $productContext,
        ]);
        $result1 = callAIWithValidation($prompt1, $config, 1);
        $stage1 = $result1['content'];
        $stage1Tokens = $result1['usage']['total_tokens'] ?? 0;
        $productTokens += $stage1Tokens;
        logMsg("\xe2\x94\x9c\xe2\x94\x80 Stage 1: {$stageNames[1]} (" . round(microtime(true) - $stageStart, 1) . "s, {$stage1Tokens} Tokens)");

        // ── Stage 2: Nutzenargumentation ───────────────────────────────
        $stageStart = microtime(true);
        $prompt2 = loadPrompt('gen-desc.2.benefit-argumentation', [
            'productContext' => $productContext,
            'previousText'  => $stage1,
        ]);
        $result2 = callAIWithValidation($prompt2, $config, 2);
        $stage2 = $result2['content'];
        $stage2Tokens = $result2['usage']['total_tokens'] ?? 0;
        $productTokens += $stage2Tokens;
        logMsg("\xe2\x94\x9c\xe2\x94\x80 Stage 2: {$stageNames[2]} (" . round(microtime(true) - $stageStart, 1) . "s, {$stage2Tokens} Tokens)");

        // ── Stage 3: SEO-Optimierung ───────────────────────────────────
        $stageStart = microtime(true);
        $prompt3 = loadPrompt('gen-desc.3.seo-optimization', [
            'produktbezeichnung' => $product['produktbezeichnung'],
            'produktkategorie'   => $product['produktkategorie'],
            'previousText'       => $stage2,
        ]);
        $result3 = callAIWithValidation($prompt3, $config, 3);
        $stage3 = $result3['content'];
        $stage3Tokens = $result3['usage']['total_tokens'] ?? 0;
        $productTokens += $stage3Tokens;
        logMsg("\xe2\x94\x9c\xe2\x94\x80 Stage 3: {$stageNames[3]} (" . round(microtime(true) - $stageStart, 1) . "s, {$stage3Tokens} Tokens)");

        // ── Stage 4: Qualitätskontrolle ───────────────────────────────
        $stageStart = microtime(true);
        $prompt4 = loadPrompt('gen-desc.4.quality-control', [
            'productContext' => $productContext,
            'previousText'  => $stage3,
        ]);
        $result4 = callAIWithValidation($prompt4, $config, 4, [
            'product' => $product,
            'config'  => $config,
        ]);
        $finalDescription = $result4['content'];
        $stage4Tokens = $result4['usage']['total_tokens'] ?? 0;
        $productTokens += $stage4Tokens;
        logMsg("\xe2\x94\x9c\xe2\x94\x80 Stage 4: {$stageNames[4]} (" . round(microtime(true) - $stageStart, 1) . "s, {$stage4Tokens} Tokens)");

        // ── Stage 5: Kurztexte ─────────────────────────────────────────
        $stageStart = microtime(true);
        $prompt5 = loadPrompt('gen-desc.5.short-texts', [
            'produktbezeichnung' => $product['produktbezeichnung'],
            'finalDescription'   => $finalDescription,
        ]);

        // JSON-Schema für strukturierte Ausgabe (wenn Provider es unterstützt)
        $responseFormat = null;
        if ($config['supportsJsonSchema']) {
            $responseFormat = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name'   => 'short_texts',
                    'strict' => true,
                    'schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'kurzbeschreibung' => ['type' => 'string'],
                            'meta_description' => ['type' => 'string'],
                        ],
                        'required'             => ['kurzbeschreibung', 'meta_description'],
                        'additionalProperties' => false,
                    ],
                ],
            ];
        }

        $result5 = callAI($prompt5, $config, 5, $responseFormat);
        $shortTexts = parseShortTextsValidated($result5['content']);
        $stage5Tokens = $result5['usage']['total_tokens'] ?? 0;
        $productTokens += $stage5Tokens;

        // Korrektur-Retries bei Short-Text-Verstößen
        if (!empty($shortTexts['violations'])) {
            for ($retry = 1; $retry <= 2; $retry++) {
                logMsg("\xe2\x94\x82  \xe2\x94\x94\xe2\x94\x80 Retry $retry/2: " . implode('; ', $shortTexts['violations']), 'WARNING');
                $correctionPrompt5 = buildCorrectionPrompt($prompt5, $result5['content'], $shortTexts['violations']);
                $result5 = callAI($correctionPrompt5, $config, 5, $responseFormat);
                $shortTexts = parseShortTextsValidated($result5['content']);
                $retryTokens = $result5['usage']['total_tokens'] ?? 0;
                $stage5Tokens += $retryTokens;
                $productTokens += $retryTokens;
                if (empty($shortTexts['violations'])) {
                    break;
                }
            }
        }

        logMsg("\xe2\x94\x9c\xe2\x94\x80 Stage 5: {$stageNames[5]} (" . round(microtime(true) - $stageStart, 1) . "s, {$stage5Tokens} Tokens)");

        // Ergebnis zusammenstellen
        $charCount = mb_strlen($finalDescription);
        $productDuration = round(microtime(true) - $productStartTime, 1);

        $productResult = array_merge($product, [
            'beschreibung'     => $finalDescription,
            'kurzbeschreibung' => $shortTexts['kurz'],
            'meta_description' => $shortTexts['meta'],
            'zeichenanzahl'    => $charCount,
            'generiert_am'     => date('Y-m-d H:i:s'),
        ]);

        $output[] = $productResult;

        // Cache mit Input-Hash speichern (für Hash-basierte Invalidierung)
        $cache[$artikelnummer] = array_merge($productResult, ['_inputHash' => $inputHash]);
        saveCache($cache, $config['cacheFile']);

        // Output-Datei aktualisieren (ohne internen _inputHash)
        $cleanOutput = array_map(function ($item) {
            unset($item['_inputHash']);
            return $item;
        }, $output);
        file_put_contents(
            $config['outputFile'],
            json_encode($cleanOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $stats['totalTokens'] += $productTokens;
        $stats['processed']++;

        logMsg("\xe2\x94\x94\xe2\x94\x80 Fertig ({$productDuration}s, {$productTokens} Tokens, {$charCount} Zeichen)", 'SUCCESS');
        logMsg(str_repeat("\xe2\x94\x80", 70));

    } catch (Exception $e) {
        $stats['errors']++;
        logMsg("\xe2\x94\x94\xe2\x94\x80 FEHLER: " . $e->getMessage(), 'ERROR');
        logMsg(str_repeat("\xe2\x94\x80", 70));
    }
}

// ============================================================================
// STATISTIKEN
// ============================================================================

$duration = microtime(true) - $stats['startTime'];
logMsg("");
logMsg("=== ZUSAMMENFASSUNG ===", 'SUCCESS');
logMsg("Verarbeitet: {$stats['processed']} | Übersprungen: {$stats['skipped']} | Fehler: {$stats['errors']}");
logMsg("Gesamt-Tokens: {$stats['totalTokens']}");
logMsg("Dauer: " . formatDuration($duration));
if ($stats['processed'] > 0) {
    $avgTime = round($duration / ($stats['processed'] + $stats['skipped']), 1);
    logMsg("Durchschnitt: {$avgTime}s pro Produkt");
}
logMsg("Ergebnisse: {$config['outputFile']}");

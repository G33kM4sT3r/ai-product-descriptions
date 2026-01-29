<?php

declare(strict_types=1);

/**
 * Produktbeschreibungs-Generator
 * 
 * Generiert SEO-optimierte Produktbeschreibungen mittels KI
 */

require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/gen-desc.conf.php';

if (!file_exists($config['inputFile'])) {
    throw new RuntimeException('Input JSON nicht gefunden: ' . $config['inputFile']);
}

/**
 * Statistiken
 */
$stats = [
    'processed' => 0,
    'skipped' => 0,
    'errors' => 0,
    'totalTokens' => 0,
    'startTime' => microtime(true),
];

/**
 * JSON laden
 */
$products = json_decode(file_get_contents($config['inputFile']), true, 512, JSON_THROW_ON_ERROR);

/**
 * Cache laden (für Fortsetzung nach Abbruch)
 */
$cache = loadCache($config['cacheFile']);

$output = [];

logMsg('Starte Generierung...');
logMsg("Modell: {$config['model']}");
logMsg("Produkte zu verarbeiten: " . count($products));

/**
 * Output-Datei initialisieren
 */
file_put_contents($config['outputFile'], json_encode([], JSON_PRETTY_PRINT));

/**
 * Validiert, ob kritische Fakten aus den Originaldaten im Text vorkommen
 * 
 * @param string $text    Der generierte Text
 * @param array  $product Die Originalen Produktdaten
 * @param array  $config  Konfiguration mit criticalKeys
 * @return array          ['valid' => bool, 'found' => array, 'missing' => array]
 */
function validateFactsInText(string $text, array $product, array $config): array
{
    $missingFacts = [];
    $foundFacts = [];
    
    $criticalKeys = $config['criticalKeys'] ?? [];
    
    foreach ($product['technische_spezifikationen'] ?? [] as $spec) {
        if (in_array($spec['key'], $criticalKeys)) {
            // Wert normalisieren für Suche (z.B. "18 V" -> "18")
            $searchValue = preg_replace('/[^0-9,.]/', '', $spec['value']);
            if (!empty($searchValue) && stripos($text, $searchValue) !== false) {
                $foundFacts[] = "{$spec['key']}: {$spec['value']}";
            } else {
                $missingFacts[] = "{$spec['key']}: {$spec['value']}";
            }
        }
    }
    
    return [
        'valid' => count($missingFacts) <= 2, // Toleranz für 1-2 fehlende Fakten
        'found' => $foundFacts,
        'missing' => $missingFacts
    ];
}

/**
 * Hauptschleife
 */
foreach ($products as $index => $product) {
    $artikelnummer = $product['artikelnummer'];
    
    // Cache-Check: Bereits verarbeitet?
    if (isset($cache[$artikelnummer])) {
        $output[] = $cache[$artikelnummer];
        $stats['skipped']++;
        logMsg("Produkt $artikelnummer übersprungen (aus Cache)", 'WARNING');
        continue;
    }
    
    try {
        $productContext = formatProductContext($product);
        
        // Token-Zähler für dieses Produkt
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;

        logMsg("[$artikelnummer] Stufe 1/5: Faktenextraktion...");
        
        // Stufe 1 – Strukturierte Basisbeschreibung mit Faktenextraktion
        $prompt1 = loadPrompt('gen-desc.1.fact-extraction', [
            'productContext' => $productContext,
        ]);
        $result1 = callAI($prompt1, 0.15, $config);
        $stage1 = $result1['content'];
        $totalPromptTokens += $result1['usage']['prompt_tokens'];
        $totalCompletionTokens += $result1['usage']['completion_tokens'];

        logMsg("[$artikelnummer] Stufe 2/5: Nutzenargumentation...");
        
        // Stufe 2 – Nutzenargumentation & Zielgruppenansprache
        $prompt2 = loadPrompt('gen-desc.2.benefit-argumentation', [
            'productContext' => $productContext,
            'previousText'   => $stage1,
        ]);
        $result2 = callAI($prompt2, 0.2, $config);
        $stage2 = $result2['content'];
        $totalPromptTokens += $result2['usage']['prompt_tokens'];
        $totalCompletionTokens += $result2['usage']['completion_tokens'];

        logMsg("[$artikelnummer] Stufe 3/5: SEO-Optimierung..."); 
        
        // Stufe 3 – SEO-Optimierung & Lesbarkeit
        $prompt3 = loadPrompt('gen-desc.3.seo-optimization', [
            'produktbezeichnung' => $product['produktbezeichnung'],
            'produktkategorie'   => $product['produktkategorie'],
            'previousText'       => $stage2,
        ]);
        $result3 = callAI($prompt3, 0.15, $config);
        $stage3 = $result3['content'];
        $totalPromptTokens += $result3['usage']['prompt_tokens'];
        $totalCompletionTokens += $result3['usage']['completion_tokens'];

        logMsg("[$artikelnummer] Stufe 4/5: Qualitätskontrolle...");
        
        // Stufe 4 – Finale Qualitätskontrolle & Formatierung
        $prompt4 = loadPrompt('gen-desc.4.quality-control', [
            'productContext' => $productContext,
            'previousText'   => $stage3,
        ]);
        $result4 = callAI($prompt4, 0.1, $config);
        $finalDescription = $result4['content'];
        $totalPromptTokens += $result4['usage']['prompt_tokens'];
        $totalCompletionTokens += $result4['usage']['completion_tokens'];

        // Faktenvalidierung
        $validation = validateFactsInText($finalDescription, $product, $config);
        if (!$validation['valid']) {
            logMsg("[$artikelnummer] Warnung: Möglicherweise fehlende Fakten: " . implode(', ', $validation['missing']), 'WARNING');
        }

        logMsg("[$artikelnummer] Stufe 5/5: Zusätzliche Textvarianten...");
        
        // Stufe 5 – Kurzbeschreibung und Meta-Description generieren
        $prompt5 = loadPrompt('gen-desc.5.short-texts', [
            'produktbezeichnung' => $product['produktbezeichnung'],
            'finalDescription'   => $finalDescription,
        ]);
        $result5 = callAI($prompt5, 0.15, $config);
        
        $shortTexts = parseShortTexts($result5['content']);
        $totalPromptTokens += $result5['usage']['prompt_tokens'];
        $totalCompletionTokens += $result5['usage']['completion_tokens'];

        // Ergebnis zusammenstellen
        $productResult = array_merge($product, [
            'beschreibung'       => $finalDescription,
            'kurzbeschreibung'   => $shortTexts['kurz'],
            'meta_description'   => $shortTexts['meta'],
            'zeichenanzahl'      => mb_strlen($finalDescription),
            'generiert_am'       => date('Y-m-d H:i:s'),
        ]);

        $output[] = $productResult;
        $cache[$artikelnummer] = $productResult;

        // Sofort in Datei und Cache schreiben
        file_put_contents(
            $config['outputFile'],
            json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        saveCache($cache, $config['cacheFile']);

        $totalTokens = $totalPromptTokens + $totalCompletionTokens;
        $stats['totalTokens'] += $totalTokens;
        $stats['processed']++;
        
        $progress = round((($stats['processed'] + $stats['skipped']) / count($products)) * 100);
        logMsg("[$artikelnummer] ✓ Fertig | {$totalTokens} Tokens | {$progress}% Fortschritt", 'SUCCESS');
        
    } catch (Exception $e) {
        $stats['errors']++;
        logMsg("[$artikelnummer] Fehler: " . $e->getMessage(), 'ERROR');
    }
}

/**
 * Finale Statistiken
 */
$duration = microtime(true) - $stats['startTime'];
logMsg("FERTIG!", 'SUCCESS');
logMsg("Verarbeitet: {$stats['processed']} | Übersprungen: {$stats['skipped']} | Fehler: {$stats['errors']}");
logMsg("Gesamt-Tokens: {$stats['totalTokens']}");
logMsg("Dauer: " . formatDuration($duration));
logMsg("Ergebnisse in: {$config['outputFile']}");

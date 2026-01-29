<?php

declare(strict_types=1);

/**
 * Markdown-Generator für Produktdaten
 * 
 * Erstellt aus JSON-Produktdaten individuelle Markdown-Dateien
 */

require_once __DIR__ . '/inc.php';
require_once __DIR__ . '/gen-markdown.conf.php';

if (!file_exists($config['inputFile'])) {
    throw new RuntimeException('Input JSON nicht gefunden: ' . $config['inputFile']);
}

/**
 * Erstellt eine Markdown-Tabelle aus Key-Value-Paaren
 */
function createMarkdownTable(array $items, string $keyHeader = 'Eigenschaft', string $valueHeader = 'Wert'): string
{
    if (empty($items)) {
        return '*Keine Daten vorhanden*';
    }
    
    $lines = [
        "| $keyHeader | $valueHeader |",
        "|:---|:---|",
    ];
    
    foreach ($items as $item) {
        $key = trim($item['key'] ?? '');
        $value = trim($item['value'] ?? '');
        
        // Leere Einträge überspringen
        if (empty($key) && empty($value)) {
            continue;
        }
        
        // Sonderzeichen für Markdown-Tabellen escapen
        $key = escapeMarkdownTableCell($key);
        $value = escapeMarkdownTableCell($value);
        
        $lines[] = "| $key | $value |";
    }
    
    return implode("\n", $lines);
}

/**
 * Escaped Sonderzeichen in Markdown-Tabellenzellen
 */
function escapeMarkdownTableCell(string $text): string
{
    // Zeilenumbrüche entfernen (würden Tabelle zerstören)
    $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
    // Pipe-Zeichen escapen
    $text = str_replace('|', '\\|', $text);
    // Mehrfache Leerzeichen reduzieren
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Erstellt den Markdown-Inhalt für ein Produkt
 */
function generateProductMarkdown(array $product): string
{
    // Felder extrahieren und bereinigen
    $bezeichnung = trim($product['produktbezeichnung'] ?? 'Unbekanntes Produkt');
    $artikelnummer = trim($product['artikelnummer'] ?? 'N/A');
    $kategorie = trim($product['produktkategorie'] ?? 'Keine Kategorie');
    $beschreibung = trim($product['beschreibung'] ?? '');
    $metaDescription = trim($product['meta_description'] ?? '');
    $kurzbeschreibung = trim($product['kurzbeschreibung'] ?? '');
    
    // Blockquote-Inhalt darf keine Zeilenumbrüche haben (nur erste Zeile oder alles in eine Zeile)
    $kurzbeschreibung = str_replace(["\r\n", "\r", "\n"], ' ', $kurzbeschreibung);
    $kurzbeschreibung = preg_replace('/\s+/', ' ', $kurzbeschreibung);
    
    // Fallbacks für leere Felder
    if (empty($kurzbeschreibung)) {
        $kurzbeschreibung = $bezeichnung;
    }
    if (empty($beschreibung)) {
        $beschreibung = '*Keine Beschreibung vorhanden*';
    }
    if (empty($metaDescription)) {
        $metaDescription = '*Keine Meta-Description vorhanden*';
    }
    $preis = isset($product['preis']) ? number_format($product['preis'], 2, ',', '.') . ' €' : 'N/A';
    
    $techSpecs = $product['technische_spezifikationen'] ?? [];
    $eigenschaften = $product['eigenschaften'] ?? [];
    
    // Tabellen vorab generieren
    $techTable = createMarkdownTable($techSpecs, 'Spezifikation', 'Wert');
    $eigenschaftenTable = createMarkdownTable($eigenschaften, 'Eigenschaft', 'Wert');
    
    $markdown = <<<MD
# $bezeichnung

> $kurzbeschreibung

## Produktinformationen

| | |
|:---|:---|
| **Artikelnummer** | $artikelnummer |
| **Kategorie** | $kategorie |
| **Preis** | **$preis** |

---

## Produktbeschreibung

$beschreibung

---

## SEO

**Meta-Description:**

$metaDescription

---

## Technische Spezifikationen

$techTable

---

## Eigenschaften

$eigenschaftenTable

MD;
    
    return $markdown;
}

/**
 * Bereinigt einen Dateinamen
 */
function sanitizeFilename(string $filename): string
{
    // Nur alphanumerische Zeichen, Bindestriche und Unterstriche erlauben
    return preg_replace('/[^a-zA-Z0-9\-_]/', '', $filename);
}

// ============================================================================
// HAUPTPROGRAMM
// ============================================================================

logMsg('Starte Markdown-Generierung...');

// Output-Verzeichnis erstellen falls nicht vorhanden
if (!is_dir($config['outputDir'])) {
    mkdir($config['outputDir'], 0755, true);
    logMsg("Verzeichnis erstellt: {$config['outputDir']}");
}

// JSON laden
$products = json_decode(file_get_contents($config['inputFile']), true, 512, JSON_THROW_ON_ERROR);
logMsg("Produkte geladen: " . count($products));

// Statistiken
$stats = [
    'created' => 0,
    'overwritten' => 0,
    'errors' => 0,
];

// Produkte verarbeiten
foreach ($products as $product) {
    $artikelnummer = $product['artikelnummer'] ?? 'unknown';
    
    try {
        // Markdown generieren
        $markdown = generateProductMarkdown($product);
        
        // Dateiname aus Artikelnummer
        $filename = sanitizeFilename($artikelnummer) . '.md';
        $filepath = $config['outputDir'] . '/' . $filename;
        
        // Prüfen ob Datei bereits existiert
        $fileExists = file_exists($filepath);
        
        // Datei schreiben (überschreibt automatisch)
        file_put_contents($filepath, $markdown);
        
        if ($fileExists) {
            $stats['overwritten']++;
            logMsg("[$artikelnummer] ↻ $filename überschrieben", 'WARNING');
        } else {
            $stats['created']++;
            logMsg("[$artikelnummer] ✓ $filename erstellt", 'SUCCESS');
        }
        
    } catch (Exception $e) {
        $stats['errors']++;
        logMsg("[$artikelnummer] Fehler: " . $e->getMessage(), 'ERROR');
    }
}

// Finale Statistiken
logMsg("FERTIG!", 'SUCCESS');
logMsg("Neu erstellt: {$stats['created']} | Überschrieben: {$stats['overwritten']} | Fehler: {$stats['errors']}");
logMsg("Ausgabeverzeichnis: {$config['outputDir']}");

<?php

declare(strict_types=1);

// System-Zeitzone verwenden
date_default_timezone_set(date_default_timezone_get() ?: 'Europe/Berlin');

/**
 * Allgemeine Hilfsfunktionen für KI-gestützte Textgenerierung
 */

/**
 * Log-Funktion mit Timestamp und optionalem Level
 * 
 * @param string $message Die Log-Nachricht
 * @param string $level   Log-Level: INFO, SUCCESS, WARNING, ERROR
 */
function logMsg(string $message, string $level = 'INFO'): void
{
    $colors = [
        'INFO'    => "\033[0m",      // Normal
        'SUCCESS' => "\033[32m",     // Grün
        'WARNING' => "\033[33m",     // Gelb
        'ERROR'   => "\033[31m",     // Rot
    ];
    $reset = "\033[0m";
    $color = $colors[$level] ?? $colors['INFO'];
    
    echo "{$color}[" . date('Y-m-d H:i:s') . "] [$level] $message{$reset}\n";
}

/**
 * Formatiert eine Dauer in Sekunden zu lesbarem Format
 * 
 * @param float $seconds Die Dauer in Sekunden
 * @return string Formatierte Dauer (Sekunden, Min/Sek oder Std/Min/Sek)
 */
function formatDuration(float $seconds): string
{
    if ($seconds < 60) {
        return round($seconds, 1) . ' Sekunden';
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $secs = round($seconds % 60);
        return $minutes . ' Min ' . $secs . ' Sek';
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = round($seconds % 60);
        return $hours . ' Std ' . $minutes . ' Min ' . $secs . ' Sek';
    }
}

/**
 * Lädt einen Prompt aus einer Datei und ersetzt Variablen
 * 
 * Variablen im Format {{variableName}} werden durch die Werte aus $variables ersetzt
 * 
 * @param string $filename  Name der Prompt-Datei ohne Endung
 * @param array  $variables Assoziatives Array mit Variablennamen und Werten
 * @return string           Der Prompt mit ersetzten Variablen
 * @throws RuntimeException Wenn die Datei nicht existiert
 */
function loadPrompt(string $filename, array $variables = []): string
{
    $filepath = __DIR__ . '/prompts/' . $filename . '.prompt';
    
    if (!file_exists($filepath)) {
        throw new RuntimeException("Prompt-Datei nicht gefunden: $filepath");
    }
    
    $content = file_get_contents($filepath);
    
    // Variablen ersetzen: {{variableName}} -> Wert
    foreach ($variables as $name => $value) {
        $content = str_replace('{{' . $name . '}}', (string) $value, $content);
    }
    
    return $content;
}

/**
 * OpenAI/LM Studio API Call mit Retry-Logik und Timeout
 * 
 * @param string $prompt      Der Prompt für die KI
 * @param float  $temperature Kreativitätsparameter (0.0-1.0)
 * @param array  $config      Konfigurationsarray
 * @param int    $attempt     Aktueller Versuch (für Retry-Logik)
 * @return array              ['content' => string, 'usage' => array]
 * @throws RuntimeException   Bei API-Fehlern nach allen Retries
 */
function callAI(string $prompt, float $temperature, array $config, int $attempt = 1): array
{
    $payload = [
        'model' => $config['model'],
        'messages' => [
            ['role' => 'system', 'content' => $config['systemPrompt']],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => $temperature,
        'max_tokens' => $config['maxTokens'] ?? 10000,
        'stream' => false,
    ];

    $ch = curl_init($config['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $config['timeout'] ?? 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR)
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    unset($ch);

    // Retry bei Fehler
    if ($response === false || $httpCode >= 500) {
        if ($attempt < $config['maxRetries']) {
            logMsg("API-Fehler (Versuch $attempt/{$config['maxRetries']}): $error - Retry in {$config['retryDelay']}s...", 'WARNING');
            sleep($config['retryDelay']);
            return callAI($prompt, $temperature, $config, $attempt + 1);
        }
        throw new RuntimeException("API-Fehler nach {$config['maxRetries']} Versuchen: $error");
    }

    $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    
    if (!isset($data['choices'][0]['message']['content'])) {
        throw new RuntimeException("Ungültige API-Antwort: " . substr($response, 0, 200));
    }

    $content = trim($data['choices'][0]['message']['content']);
    
    // Markdown-Artefakte entfernen
    $content = cleanMarkdown($content);

    return [
        'content' => $content,
        'usage' => $data['usage'] ?? ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0]
    ];
}

/**
 * Entfernt häufige Markdown-Artefakte und unerwünschte Formatierungen
 * 
 * @param string $text Der zu bereinigende Text
 * @return string      Der bereinigte Text
 */
function cleanMarkdown(string $text): string
{
    // Entferne **bold** und *italic*
    $text = preg_replace('/\*{1,2}([^*]+)\*{1,2}/', '$1', $text);
    // Entferne # Headlines
    $text = preg_replace('/^#{1,6}\s*/m', '', $text);
    // Entferne Bullet Points
    $text = preg_replace('/^[-•●]\s*/m', '', $text);
    // Entferne nummerierte Listen
    $text = preg_replace('/^\d+\.\s*/m', '', $text);
    // Entferne übermäßige Leerzeilen
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    
    return trim($text);
}

/**
 * Formatiert Produktdaten als lesbaren Kontext für die KI
 * 
 * @param array $product Die Produktdaten
 * @return string        Formatierter Kontext-String
 */
function formatProductContext(array $product): string
{
    $lines = [
        "PRODUKT: {$product['produktbezeichnung']}",
        "KATEGORIE: {$product['produktkategorie']}",
        "ARTIKELNR: {$product['artikelnummer']}",
        "",
        "TECHNISCHE SPEZIFIKATIONEN:"
    ];
    
    foreach ($product['technische_spezifikationen'] ?? [] as $spec) {
        $lines[] = "  • {$spec['key']}: {$spec['value']}";
    }
    
    $lines[] = "";
    $lines[] = "EIGENSCHAFTEN:";
    
    foreach ($product['eigenschaften'] ?? [] as $prop) {
        $lines[] = "  • {$prop['key']}: {$prop['value']}";
    }
    
    return implode("\n", $lines);
}

/**
 * Speichert den Cache in eine JSON-Datei
 * 
 * @param array  $cache     Die Cache-Daten
 * @param string $cacheFile Pfad zur Cache-Datei
 */
function saveCache(array $cache, string $cacheFile): void
{
    file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Parst Kurzbeschreibung und Meta-Description aus der AI-Antwort
 * 
 * @param string $response Die AI-Antwort
 * @return array           ['kurz' => string, 'meta' => string]
 */
function parseShortTexts(string $response): array
{
    $kurz = '';
    $meta = '';
    
    if (preg_match('/KURZ:\s*(.+?)(?=META:|$)/si', $response, $matches)) {
        $kurz = trim($matches[1]);
    }
    if (preg_match('/META:\s*(.+?)$/si', $response, $matches)) {
        $meta = trim($matches[1]);
    }
    
    // Fallback: Wenn Parsing fehlschlägt, Text aufteilen
    if (empty($kurz) && empty($meta)) {
        $lines = array_filter(explode("\n", $response));
        $kurz = $lines[0] ?? '';
        $meta = $lines[1] ?? $kurz;
    }
    
    return ['kurz' => $kurz, 'meta' => $meta];
}

/**
 * Lädt den Cache aus einer Datei
 * 
 * @param string $cacheFile Pfad zur Cache-Datei
 * @return array            Die Cache-Daten oder leeres Array
 */
function loadCache(string $cacheFile): array
{
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true) ?? [];
        if (!empty($cache)) {
            logMsg("Cache geladen: " . count($cache) . " bereits verarbeitete Produkte", 'INFO');
        }
        return $cache;
    }
    return [];
}

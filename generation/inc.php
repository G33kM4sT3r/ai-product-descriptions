<?php

declare(strict_types=1);

// System-Zeitzone verwenden
date_default_timezone_set(date_default_timezone_get() ?: 'Europe/Berlin');

/**
 * Allgemeine Hilfsfunktionen für KI-gestützte Textgenerierung
 *
 * Enthält Provider-Abstraktion, API-Client mit Retry-Logik,
 * Validierungs-Framework, Prompt-Loading und Caching.
 */

// ============================================================================
// LOGGING
// ============================================================================

/**
 * Gibt eine Log-Nachricht mit Timestamp und Level aus
 *
 * Unterstützt farbige Terminal-Ausgabe und optionales Datei-Logging.
 * Die Datei-Ausgabe enthält keine ANSI-Farbcodes.
 * Beim ersten Aufruf mit $logFile wird dieser Pfad als Standard gespeichert.
 *
 * @param string      $message Die Log-Nachricht
 * @param string      $level   Log-Level: INFO, SUCCESS, WARNING, ERROR
 * @param string|null $logFile Optionaler Pfad zur Log-Datei (null = nur Terminal)
 */
function logMsg(string $message, string $level = 'INFO', ?string $logFile = null): void
{
    static $configLogFile = null;

    if ($logFile !== null) {
        $configLogFile = $logFile;
    }
    $activeLogFile = $logFile ?? $configLogFile;

    $colors = [
        'INFO'    => "\033[0m",
        'SUCCESS' => "\033[32m",
        'WARNING' => "\033[33m",
        'ERROR'   => "\033[31m",
    ];
    $reset = "\033[0m";
    $color = $colors[$level] ?? $colors['INFO'];

    $timestamp = date('Y-m-d H:i:s');
    $paddedLevel = str_pad("[$level]", 9);

    // Terminal-Ausgabe (mit Farbe)
    echo "{$color}[$timestamp] $paddedLevel $message{$reset}\n";

    // Datei-Ausgabe (ohne ANSI-Farbcodes)
    if ($activeLogFile !== null) {
        $logDir = dirname($activeLogFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents(
            $activeLogFile,
            "[$timestamp] $paddedLevel $message\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

// ============================================================================
// ZEITFORMATIERUNG
// ============================================================================

/**
 * Formatiert eine Dauer in Sekunden zu lesbarem Format
 *
 * @param float $seconds Die Dauer in Sekunden
 * @return string Formatierte Dauer (z.B. "2.3 Sekunden", "1 Min 30 Sek")
 */
function formatDuration(float $seconds): string
{
    if ($seconds < 60) {
        return round($seconds, 1) . ' Sekunden';
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $secs = round(fmod($seconds, 60));
        return $minutes . ' Min ' . $secs . ' Sek';
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(fmod($seconds, 3600) / 60);
        $secs = round(fmod($seconds, 60));
        return $hours . ' Std ' . $minutes . ' Min ' . $secs . ' Sek';
    }
}

// ============================================================================
// PROVIDER-KONFIGURATION
// ============================================================================

/**
 * Erkennt Provider-Fähigkeiten automatisch und setzt Standardwerte
 *
 * Prüft den Provider-Typ und das Modell, um zu bestimmen ob System-Rolle
 * und JSON-Schema unterstützt werden. Manuelle Werte in der Config haben Vorrang.
 *
 * Provider-Defaults:
 * - lmstudio + gemma*: supportsSystemRole=false (Gemma hat keine System-Rolle)
 * - lmstudio + andere: supportsSystemRole=true
 * - openai/anthropic:  supportsSystemRole=true
 *
 * @param array $config Konfigurationsarray (wird per Referenz modifiziert)
 */
function resolveProviderCapabilities(array &$config): void
{
    $provider = $config['provider'] ?? 'lmstudio';
    $model = strtolower($config['model'] ?? '');

    $defaults = match ($provider) {
        'openai'    => ['supportsSystemRole' => true,  'supportsJsonSchema' => true],
        'anthropic' => ['supportsSystemRole' => true,  'supportsJsonSchema' => false],
        'lmstudio'  => [
            // Gemma-Modelle haben keine System-Rolle — Prompt wird in User-Nachricht eingebettet
            'supportsSystemRole' => !str_contains($model, 'gemma'),
            'supportsJsonSchema' => true,
        ],
        default     => ['supportsSystemRole' => true,  'supportsJsonSchema' => false],
    };

    // null = auto-detect, true/false = manuell konfiguriert
    $config['supportsSystemRole'] = $config['supportsSystemRole'] ?? $defaults['supportsSystemRole'];
    $config['supportsJsonSchema'] = $config['supportsJsonSchema'] ?? $defaults['supportsJsonSchema'];
}

/**
 * Löst Stage-spezifische Konfiguration auf mit Fallback auf globale Werte
 *
 * Reihenfolge: Stage-Wert > Globaler Config-Wert > Hardcoded Default
 *
 * @param array $config Globale Konfiguration
 * @param int   $stage  Stage-Nummer (1-5), 0 = nur globale Werte
 * @return array        ['model' => string, 'temperature' => float, 'maxTokens' => int]
 */
function resolveStageConfig(array $config, int $stage): array
{
    $stageConfig = $config['stages'][$stage] ?? [];

    return [
        'model'       => $stageConfig['model'] ?? $config['model'],
        'temperature' => $stageConfig['temperature'] ?? match ($stage) {
            1 => 0.10, 2 => 0.35, 3 => 0.15, 4 => 0.05, 5 => 0.10,
            default => 0.15,
        },
        'maxTokens'   => $stageConfig['maxTokens'] ?? $config['maxTokens'] ?? -1,
    ];
}

// ============================================================================
// PROMPT-VERWALTUNG
// ============================================================================

/**
 * Lädt einen Prompt aus einer Datei und ersetzt Variablen
 *
 * Variablen im Format {{variableName}} werden durch die Werte aus $variables ersetzt.
 * Prompt-Dateien liegen in generation/prompts/ mit der Endung .prompt.
 *
 * @param string $filename  Name der Prompt-Datei ohne Endung (z.B. 'gen-desc.system')
 * @param array  $variables Assoziatives Array mit Variablennamen und Werten
 * @return string           Der Prompt mit ersetzten Variablen
 * @throws RuntimeException Wenn die Prompt-Datei nicht existiert
 */
function loadPrompt(string $filename, array $variables = []): string
{
    $filepath = __DIR__ . '/prompts/' . $filename . '.prompt';

    if (!file_exists($filepath)) {
        throw new RuntimeException("Prompt-Datei nicht gefunden: $filepath");
    }

    return array_reduce(
        array_keys($variables),
        fn(string $content, string $name) => str_replace('{{' . $name . '}}', (string) $variables[$name], $content),
        file_get_contents($filepath)
    );
}

// ============================================================================
// API-CLIENT
// ============================================================================

/**
 * Führt einen API-Aufruf an den konfigurierten LLM-Provider durch
 *
 * Unterstützt LM Studio, OpenAI und Anthropic APIs. Erkennt automatisch
 * ob der Provider System-Nachrichten unterstützt und passt die Nachrichtenstruktur
 * entsprechend an. Implementiert iterative Retry-Logik bei Server-Fehlern.
 *
 * Bei Modellen ohne System-Rolle (z.B. Gemma) wird der System-Prompt
 * mit Trennzeichen in die User-Nachricht eingebettet.
 *
 * @param string     $prompt         Der Prompt für die KI
 * @param array      $config         Konfigurationsarray mit Provider- und API-Einstellungen
 * @param int        $stage          Pipeline-Stage (1-5) für per-Stage-Konfiguration, 0 = global
 * @param array|null $responseFormat Optionales JSON-Schema für strukturierte Ausgabe (Stage 5)
 * @return array                     ['content' => string, 'usage' => array{prompt_tokens: int, completion_tokens: int, total_tokens: int}]
 * @throws RuntimeException          Bei API-Fehlern nach allen Retries oder bei Client-Fehlern (4xx)
 */
function callAI(string $prompt, array $config, int $stage = 0, ?array $responseFormat = null): array
{
    $stageConfig = resolveStageConfig($config, $stage);

    // Nachrichtenstruktur: System-Rolle oder eingebetteter System-Prompt
    if ($config['supportsSystemRole']) {
        $messages = [
            ['role' => 'system', 'content' => $config['systemPrompt']],
            ['role' => 'user', 'content' => $prompt],
        ];
    } else {
        $combinedPrompt = "--- ANWEISUNGEN ---\n"
            . $config['systemPrompt']
            . "\n--- AUFGABE ---\n"
            . $prompt;
        $messages = [
            ['role' => 'user', 'content' => $combinedPrompt],
        ];
    }

    $payload = [
        'model'       => $stageConfig['model'],
        'messages'    => $messages,
        'temperature' => $stageConfig['temperature'],
        'max_tokens'  => $stageConfig['maxTokens'],
        'stream'      => false,
    ];

    if ($responseFormat !== null) {
        $payload['response_format'] = $responseFormat;
    }

    $headers = ['Content-Type: application/json'];
    if (!empty($config['apiKey'])) {
        $headers[] = 'Authorization: Bearer ' . $config['apiKey'];
    }

    $maxRetries = $config['maxRetries'] ?? 3;
    $retryDelay = $config['retryDelay'] ?? 2;
    $lastError = '';
    $httpCode = 0;

    // Iterative Retry-Schleife (statt rekursiv, um Stack-Overflow zu vermeiden)
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($config['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $config['timeout'] ?? 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastError = curl_error($ch);
        curl_close($ch);

        // Erfolgreiche Antwort
        if ($response !== false && $httpCode >= 200 && $httpCode < 400) {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new RuntimeException("Ungültige API-Antwort: " . substr((string) $response, 0, 200));
            }

            $content = trim($data['choices'][0]['message']['content']);
            $content = cleanMarkdown($content);

            return [
                'content' => $content,
                'usage'   => $data['usage'] ?? [
                    'prompt_tokens'     => 0,
                    'completion_tokens' => 0,
                    'total_tokens'      => 0,
                ],
            ];
        }

        // 4xx Client-Fehler werden nicht wiederholt
        if ($response !== false && $httpCode >= 400 && $httpCode < 500) {
            throw new RuntimeException(
                "API Client-Fehler (HTTP $httpCode): " . substr((string) $response, 0, 300)
            );
        }

        // 5xx oder Netzwerkfehler: Retry mit Wartezeit
        if ($attempt < $maxRetries) {
            logMsg(
                "API-Fehler Versuch $attempt/$maxRetries: "
                . ($lastError ?: "HTTP $httpCode")
                . " — Retry in {$retryDelay}s...",
                'WARNING'
            );
            sleep($retryDelay);
        }
    }

    throw new RuntimeException(
        "API-Fehler nach $maxRetries Versuchen: " . ($lastError ?: "HTTP $httpCode")
    );
}

// ============================================================================
// TEXT-BEREINIGUNG
// ============================================================================

/**
 * Entfernt häufige Markdown-Artefakte und unerwünschte Formatierungen
 *
 * LLMs fügen oft Markdown-Formatierung ein obwohl reiner Fliesstext
 * angefordert wurde. Diese Funktion bereinigt die gängigsten Artefakte.
 *
 * @param string $text Der zu bereinigende Text
 * @return string      Der bereinigte Text ohne Markdown-Formatierung
 */
function cleanMarkdown(string $text): string
{
    return $text
        |> (fn(string $t) => preg_replace('/\*{1,2}([^*]+)\*{1,2}/', '$1', $t))
        |> (fn(string $t) => preg_replace('/^#{1,6}\s*/m', '', $t))
        |> (fn(string $t) => preg_replace('/^[-•●]\s*/m', '', $t))
        |> (fn(string $t) => preg_replace('/^\d+\.\s*/m', '', $t))
        |> (fn(string $t) => preg_replace('/\n{3,}/', "\n\n", $t))
        |> trim(...);
}

// ============================================================================
// PRODUKTDATEN-FORMATIERUNG
// ============================================================================

/**
 * Formatiert Produktdaten als lesbaren Kontext für die KI
 *
 * Erstellt einen strukturierten Textblock aus Produktbezeichnung, Kategorie,
 * Artikelnummer, technischen Spezifikationen und Eigenschaften.
 *
 * @param array $product Die Produktdaten mit produktbezeichnung, produktkategorie, etc.
 * @return string        Formatierter Kontext-String für Prompt-Injection
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
        $lines[] = "  - {$spec['key']}: {$spec['value']}";
    }

    $lines[] = "";
    $lines[] = "EIGENSCHAFTEN:";

    foreach ($product['eigenschaften'] ?? [] as $prop) {
        $lines[] = "  - {$prop['key']}: {$prop['value']}";
    }

    return implode("\n", $lines);
}

// ============================================================================
// VALIDIERUNG
// ============================================================================

/**
 * Validiert die Ausgabe eines Pipeline-Stages gegen definierte Kriterien
 *
 * Prüft Absatzanzahl, Wort-/Zeichenzahl und stage-spezifische Regeln.
 * Gibt eine Liste von Verstößen zurück (leer = alles OK).
 *
 * Validierungsbereiche sind bewusst etwas weiter als die Prompt-Vorgaben,
 * um kleine Abweichungen zu tolerieren und nur grobe Verstöße abzufangen.
 *
 * @param string $text  Der zu validierende Text
 * @param int    $stage Pipeline-Stage (1-5)
 * @param array  $extra Zusätzliche Daten für Validierung (z.B. 'product', 'config')
 * @return string[]     Liste der Verstöße (leer = valide)
 */
function validateStageOutput(string $text, int $stage, array $extra = []): array
{
    $violations = [];
    $paragraphs = array_filter(explode("\n\n", trim($text)), fn($p) => trim($p) !== '');
    $paragraphCount = count($paragraphs);
    $wordCount = str_word_count($text);
    $charCount = mb_strlen($text);

    switch ($stage) {
        case 1: // Faktenextraktion: 3 Absätze, 140-180 Wörter
            if ($paragraphCount !== 3) {
                $violations[] = "Absatzanzahl: $paragraphCount (erwartet: 3)";
            }
            if ($wordCount < 130 || $wordCount > 190) {
                $violations[] = "Wortanzahl: $wordCount (erwartet: 140-180)";
            }
            break;

        case 2: // Nutzenargumentation: 3 Absätze, 170-210 Wörter
            if ($paragraphCount !== 3) {
                $violations[] = "Absatzanzahl: $paragraphCount (erwartet: 3)";
            }
            if ($wordCount < 160 || $wordCount > 220) {
                $violations[] = "Wortanzahl: $wordCount (erwartet: 170-210)";
            }
            break;

        case 3: // SEO-Optimierung: 3 Absätze, 600-900 Zeichen
            if ($paragraphCount !== 3) {
                $violations[] = "Absatzanzahl: $paragraphCount (erwartet: 3)";
            }
            if ($charCount < 550 || $charCount > 950) {
                $violations[] = "Zeichenanzahl: $charCount (erwartet: 600-900)";
            }
            break;

        case 4: // Qualitätskontrolle: 3 Absätze, 650-850 Zeichen, Faktencheck
            if ($charCount < 600 || $charCount > 900) {
                $violations[] = "Zeichenanzahl: $charCount (erwartet: 650-850)";
            }
            if ($paragraphCount !== 3) {
                $violations[] = "Absatzanzahl: $paragraphCount (erwartet: 3)";
            }
            if (isset($extra['product'], $extra['config'])) {
                $factCheck = validateFactsInText($text, $extra['product'], $extra['config']);
                if (!$factCheck['valid']) {
                    $violations[] = "Fehlende Fakten: " . implode(', ', $factCheck['missing']);
                }
            }
            break;

        case 5: // Kurztexte — separate Validierung in parseShortTextsValidated()
            break;
    }

    return $violations;
}

/**
 * Baut einen Korrektur-Prompt auf Basis der gefundenen Verstöße
 *
 * Der Korrektur-Prompt enthält den fehlerhaften Text, die spezifischen
 * Verstöße und die ursprünglichen Anforderungen. Dies ermöglicht
 * dem Modell eine gezielte Korrektur statt einer kompletten Neugenerierung.
 *
 * @param string   $originalPrompt Der ursprüngliche Prompt
 * @param string   $failedOutput   Die fehlerhafte Ausgabe
 * @param string[] $violations     Liste der Verstöße
 * @return string                  Der Korrektur-Prompt
 */
function buildCorrectionPrompt(string $originalPrompt, string $failedOutput, array $violations): string
{
    $violationList = implode("\n", array_map(fn($v) => "- $v", $violations));

    return <<<PROMPT
Der vorherige Text hat folgende Qualitätskriterien nicht erfüllt:

VERSTÖSSE:
$violationList

FEHLERHAFTER TEXT:
$failedOutput

KORREKTUR-ANWEISUNG:
Korrigiere den Text so, dass alle oben genannten Verstöße behoben sind.
Behalte den Inhalt und Stil bei, passe nur die bemängelten Punkte an.

URSPRÜNGLICHE ANFORDERUNGEN:
$originalPrompt
PROMPT;
}

/**
 * Führt einen AI-Aufruf mit automatischer Validierung und Korrektur-Retries durch
 *
 * Ruft callAI() auf, validiert die Ausgabe gegen Stage-spezifische Kriterien
 * und wiederholt bei Verstößen bis zu 2 Mal mit gezieltem Korrektur-Prompt.
 * Token-Usage wird über alle Versuche akkumuliert.
 *
 * @param string $prompt Der Prompt für die KI
 * @param array  $config Konfigurationsarray
 * @param int    $stage  Pipeline-Stage (1-5)
 * @param array  $extra  Zusätzliche Validierungsdaten (z.B. 'product', 'config')
 * @return array         ['content' => string, 'usage' => array]
 */
function callAIWithValidation(string $prompt, array $config, int $stage, array $extra = []): array
{
    $maxCorrectionRetries = 2;
    $result = callAI($prompt, $config, $stage);

    $violations = validateStageOutput($result['content'], $stage, $extra);
    if (empty($violations)) {
        return $result;
    }

    for ($retry = 1; $retry <= $maxCorrectionRetries; $retry++) {
        logMsg(
            "│  └─ Retry $retry/$maxCorrectionRetries: " . implode('; ', $violations),
            'WARNING'
        );

        $correctionPrompt = buildCorrectionPrompt($prompt, $result['content'], $violations);
        $retryResult = callAI($correctionPrompt, $config, $stage);

        // Token-Usage akkumulieren
        $result['usage']['prompt_tokens'] += $retryResult['usage']['prompt_tokens'];
        $result['usage']['completion_tokens'] += $retryResult['usage']['completion_tokens'];
        $result['usage']['total_tokens'] += $retryResult['usage']['total_tokens'];

        $violations = validateStageOutput($retryResult['content'], $stage, $extra);
        if (empty($violations)) {
            $result['content'] = $retryResult['content'];
            return $result;
        }

        $result['content'] = $retryResult['content'];
    }

    logMsg(
        "│  └─ Akzeptiere besten Versuch nach $maxCorrectionRetries Korrekturen ("
        . implode('; ', $violations) . ")",
        'WARNING'
    );

    return $result;
}

// ============================================================================
// KURZTEXT-PARSING
// ============================================================================

/**
 * Parst und validiert Kurztexte aus der AI-Antwort
 *
 * Unterstützt drei Formate in Prioritätsreihenfolge:
 * 1. JSON-Objekt mit kurzbeschreibung/meta_description (für strukturierte Ausgabe)
 * 2. KURZ:/META: Label-Format (Standard-Textausgabe)
 * 3. Zeilenweise Aufteilung als Fallback
 *
 * Validiert Zeichenlängen der Kurzbeschreibung (80-130) und Meta-Description (140-155).
 *
 * @param string $response Die AI-Antwort (JSON oder Text)
 * @return array ['kurz' => string, 'meta' => string, 'violations' => string[]]
 */
function parseShortTextsValidated(string $response): array
{
    $violations = [];

    // Versuch 1: JSON-Parsing (strukturierte Ausgabe von LM Studio/OpenAI)
    $jsonData = json_decode($response, true);
    if (is_array($jsonData) && isset($jsonData['kurzbeschreibung'], $jsonData['meta_description'])) {
        $kurz = trim($jsonData['kurzbeschreibung']);
        $meta = trim($jsonData['meta_description']);
    } else {
        // Versuch 2: KURZ:/META: Label-Format
        $kurz = '';
        $meta = '';

        if (preg_match('/KURZ:\s*(.+?)(?=\s*META:|$)/si', $response, $matches)) {
            $kurz = trim($matches[1]);
        }
        if (preg_match('/META:\s*(.+?)$/si', $response, $matches)) {
            $meta = trim($matches[1]);
        }

        // Versuch 3: Zeilenweise Aufteilung als letzter Fallback
        if (empty($kurz) && empty($meta)) {
            $lines = array_values(array_filter(explode("\n", trim($response)), fn($l) => trim($l) !== ''));
            $kurz = array_first($lines) ?? '';
            $meta = array_last($lines) ?? '';
            if (!empty($kurz) || !empty($meta)) {
                $violations[] = "KURZ/META Labels nicht erkannt, Fallback auf Zeilenaufteilung";
            }
        }
    }

    // Zeichenlängen validieren
    $kurzLen = mb_strlen($kurz);
    $metaLen = mb_strlen($meta);

    if ($kurzLen < 80 || $kurzLen > 130) {
        $violations[] = "Kurzbeschreibung: $kurzLen Zeichen (erwartet: 80-130)";
    }
    if ($metaLen < 140 || $metaLen > 155) {
        $violations[] = "Meta-Description: $metaLen Zeichen (erwartet: 140-155)";
    }

    return ['kurz' => $kurz, 'meta' => $meta, 'violations' => $violations];
}

// ============================================================================
// CACHE-VERWALTUNG
// ============================================================================

/**
 * Speichert den Cache in eine JSON-Datei
 *
 * @param array  $cache     Die Cache-Daten (Artikelnummer => Produktergebnis)
 * @param string $cacheFile Pfad zur Cache-Datei
 */
function saveCache(array $cache, string $cacheFile): void
{
    file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Lädt den Cache aus einer JSON-Datei
 *
 * Gibt eine Warnung aus wenn die Cache-Datei korrupt ist (ungültiges JSON),
 * anstatt stillschweigend einen leeren Cache zurückzugeben.
 *
 * @param string $cacheFile Pfad zur Cache-Datei
 * @return array            Die Cache-Daten oder leeres Array bei Fehler
 */
function loadCache(string $cacheFile): array
{
    if (!file_exists($cacheFile)) {
        return [];
    }

    $raw = file_get_contents($cacheFile);
    if ($raw === false || trim($raw) === '') {
        logMsg("Cache-Datei leer oder nicht lesbar: $cacheFile", 'WARNING');
        return [];
    }

    $cache = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMsg(
            "Cache-Datei korrupt (JSON-Fehler: " . json_last_error_msg() . "): $cacheFile — Starte mit leerem Cache",
            'WARNING'
        );
        return [];
    }

    if (!empty($cache)) {
        logMsg("Cache geladen: " . count($cache) . " bereits verarbeitete Produkte", 'INFO');
    }

    return $cache;
}

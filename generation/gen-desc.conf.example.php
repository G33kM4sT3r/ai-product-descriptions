<?php

declare(strict_types=1);

/**
 * Konfiguration für den Produktbeschreibungs-Generator
 *
 * Diese Datei kopieren und als gen-desc.conf.php speichern:
 * cp gen-desc.conf.example.php gen-desc.conf.php
 *
 * Anpassungen:
 * - 'provider' auf den verwendeten API-Anbieter setzen
 * - 'url' und 'model' entsprechend anpassen
 * - 'apiKey' für Cloud-APIs setzen
 * - Per-Stage 'model', 'temperature', 'maxTokens' bei Bedarf überschreiben
 */

$config = [
    // =========================================================================
    // API-Provider: 'lmstudio', 'openai', 'anthropic'
    // =========================================================================
    'provider'      => 'lmstudio',

    // API-Endpunkt
    'url'           => 'http://localhost:1234/v1/chat/completions',

    // Globales Modell (wird verwendet wenn kein Stage-spezifisches Modell gesetzt)
    'model'         => 'google/gemma-3-12b',

    // API-Key (leer für lokale APIs, gesetzt für Cloud-APIs)
    'apiKey'        => '',

    // =========================================================================
    // Provider-Fähigkeiten (automatisch erkannt, manuell überschreibbar)
    // =========================================================================
    // lmstudio + gemma*: supportsSystemRole=false, supportsJsonSchema=true
    // lmstudio + andere: supportsSystemRole=true,  supportsJsonSchema=true
    // openai:            supportsSystemRole=true,  supportsJsonSchema=true
    // anthropic:         supportsSystemRole=true,  supportsJsonSchema=false
    'supportsSystemRole' => null,  // null = auto-detect, true/false = manuell
    'supportsJsonSchema' => null,  // null = auto-detect, true/false = manuell

    // =========================================================================
    // Globale Generierungs-Parameter (Fallback wenn kein Stage-Wert gesetzt)
    // =========================================================================
    'maxTokens'     => -1,         // -1 = kein Limit (LM Studio), anpassen für Cloud-APIs
    'maxRetries'    => 3,          // Wiederholungsversuche bei API-Fehlern
    'retryDelay'    => 2,          // Sekunden zwischen Wiederholungen
    'timeout'       => 120,        // CURL-Timeout in Sekunden

    // =========================================================================
    // Per-Stage Konfiguration
    //
    // Jeder Stage kann eigenes Modell, Temperatur und Token-Limit haben.
    // null = globalen Wert verwenden
    // =========================================================================
    'stages' => [
        1 => [  // Faktenextraktion — deterministische Extraktion
            'model'       => null,
            'temperature' => 0.10,
            'maxTokens'   => null,
        ],
        2 => [  // Nutzenargumentation — kreativer Stage
            'model'       => null,
            'temperature' => 0.35,
            'maxTokens'   => null,
        ],
        3 => [  // SEO-Optimierung — Balance Flexibilität/Konsistenz
            'model'       => null,
            'temperature' => 0.15,
            'maxTokens'   => null,
        ],
        4 => [  // Qualitätskontrolle — maximaler Determinismus
            'model'       => null,
            'temperature' => 0.05,
            'maxTokens'   => null,
        ],
        5 => [  // Kurztexte — Präzision bei engen Zeichenlimits
            'model'       => null,
            'temperature' => 0.10,
            'maxTokens'   => null,
        ],
    ],

    // =========================================================================
    // Ein-/Ausgabedateien
    // =========================================================================
    'inputFile'     => __DIR__ . '/../test-products.json',
    'outputFile'    => __DIR__ . '/../products_with_descriptions.json',
    'cacheFile'     => __DIR__ . '/generation_cache.json',

    // =========================================================================
    // System-Prompt (automatisch aus Datei geladen)
    // =========================================================================
    'systemPrompt'  => loadPrompt('gen-desc.system'),

    // =========================================================================
    // Logging
    // =========================================================================
    'logFile'       => null,  // null = nur Terminal, Pfad = zusätzlich in Datei loggen

    // =========================================================================
    // Validierung
    // =========================================================================
    'minDescLength' => 650,
    'maxDescLength' => 850,

    // Kritische Spezifikations-Keys für Faktenvalidierung
    'criticalKeys'  => ['Spannung', 'Leistung', 'Drehmoment', 'Laenge', 'Durchmesser', 'Inhalt', 'Format'],

    // Kategorie-spezifische kritische Keys (erweitern die globalen criticalKeys)
    'categoryCriticalKeys' => [
        'Elektrowerkzeuge'    => ['Spannung', 'Leistung', 'Drehmoment', 'Drehzahl', 'Schnitttiefe'],
        'Beleuchtung'         => ['Leistung', 'Lichtstrom', 'Farbtemperatur', 'Lichtfarbe'],
        'Farben & Lacke'      => ['Inhalt', 'Ergiebigkeit', 'Trocknungszeit'],
        'Gartengeraete'       => ['Leistung', 'Schnittbreite', 'Schnitthoehe'],
        'Sanitaer'            => ['Durchmesser', 'Laenge', 'Anschluss'],
        'Baustoffe'           => ['Format', 'Laenge', 'Breite', 'Staerke'],
        'Elektroinstallation' => ['Spannung', 'Leistung', 'Schutzklasse'],
    ],
];

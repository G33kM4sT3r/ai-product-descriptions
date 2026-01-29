<?php

declare(strict_types=1);

/**
 * Konfiguration für den Produktbeschreibungs-Generator
 * 
 * Diese Datei kopieren und als gen-desc.conf.php speichern:
 * cp gen-desc.conf.example.php gen-desc.conf.php
 */

$config = [
    // Ein- und Ausgabedateien
    'inputFile'     => __DIR__ . '/../test-products.json',
    'outputFile'    => __DIR__ . '/../products_with_descriptions.json',
    'cacheFile'     => __DIR__ . '/generation_cache.json',
    
    // System-Prompt für die KI
    'systemPrompt'  => loadPrompt('gen-desc.system'),
    
    // LM Studio / OpenAI API Konfiguration
    'url'           => 'http://localhost:1234/v1/chat/completions',
    'model'         => 'google/gemma-3-12b',
    
    // Token-Limit (-1 = kein Limit)
    'maxTokens'     => -1,
    
    // Fehlerbehandlung
    'maxRetries'    => 3,
    'retryDelay'    => 2,
    'timeout'       => 120,
    
    // Beschreibungslänge (Zeichen)
    'minDescLength' => 400,
    'maxDescLength' => 1200,

    // Kritische Spezifikations-Keys für Faktenvalidierung
    'criticalKeys'  => ['Spannung', 'Leistung', 'Drehmoment', 'Länge', 'Durchmesser', 'Inhalt', 'Format'],
];

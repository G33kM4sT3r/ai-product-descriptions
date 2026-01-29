<?php

declare(strict_types=1);

/**
 * Konfiguration für den Markdown-Generator
 * 
 * Diese Datei kopieren und als gen-markdown.conf.php speichern:
 * cp gen-markdown.conf.example.php gen-markdown.conf.php
 */

$config = [
    // Eingabedatei (JSON mit Produktbeschreibungen)
    'inputFile'  => __DIR__ . '/../products_with_descriptions.json',
    
    // Ausgabeverzeichnis für Markdown-Dateien
    'outputDir'  => __DIR__ . '/../products',
];

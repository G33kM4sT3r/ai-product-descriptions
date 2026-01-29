<?php

declare(strict_types=1);

/**
 * Produkt-Viewer für Markdown-Dateien
 * 
 * Startet mit: php -S localhost:8000 server.php
 */

$productsDir = __DIR__ . '/../products';

/**
 * Alle Markdown-Dateien laden
 */
function getProductFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    
    $files = glob($dir . '/*.md');
    $products = [];
    
    foreach ($files as $file) {
        $filename = basename($file, '.md');
        $content = file_get_contents($file);
        
        // Titel aus erster Zeile extrahieren (# Titel)
        preg_match('/^#\s+(.+)$/m', $content, $matches);
        $title = $matches[1] ?? $filename;
        
        $products[$filename] = [
            'file' => $file,
            'filename' => $filename,
            'title' => $title,
        ];
    }
    
    // Nach Artikelnummer sortieren
    ksort($products);
    
    return $products;
}

/**
 * Markdown zu HTML konvertieren (einfache Version)
 */
function markdownToHtml(string $markdown): string
{
    $html = $markdown;
    
    // Escaped Pipes temporär ersetzen (für Tabellen)
    $html = str_replace('\\|', '{{PIPE}}', $html);
    
    // Escapen
    $html = htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
    
    // Überschriften
    $html = preg_replace('/^######\s+(.+)$/m', '<h6>$1</h6>', $html);
    $html = preg_replace('/^#####\s+(.+)$/m', '<h5>$1</h5>', $html);
    $html = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $html);
    $html = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $html);
    
    // Blockquotes (auch mehrzeilige, nach HTML-Escape ist > zu &gt; geworden)
    $html = preg_replace('/^&gt;\s*(.*)$/m', '<blockquote>$1</blockquote>', $html);
    // Aufeinanderfolgende Blockquotes zusammenfassen
    $html = preg_replace('/<\/blockquote>\s*<blockquote>/s', '<br>', $html);
    
    // Horizontale Linie
    $html = preg_replace('/^---$/m', '<hr>', $html);
    
    // Fett und Kursiv
    $html = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $html);
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $html);
    
    // Code inline
    $html = preg_replace('/`(.+?)`/', '<code>$1</code>', $html);
    
    // Tabellen
    $html = preg_replace_callback('/(\|.+\|[\r\n]+)+/s', function($matches) {
        $tableContent = trim($matches[0]);
        $rows = explode("\n", $tableContent);
        $tableHtml = '<table>';
        $isHeader = true;
        
        foreach ($rows as $row) {
            $row = trim($row);
            if (empty($row)) continue;
            
            // Separator-Zeile überspringen
            if (preg_match('/^\|[\s\-:|]+\|$/', $row)) {
                continue;
            }
            
            // Zellen extrahieren
            $cells = explode('|', trim($row, '|'));
            $cells = array_map('trim', $cells);
            
            if ($isHeader) {
                $tableHtml .= '<thead><tr>';
                foreach ($cells as $cell) {
                    $tableHtml .= '<th>' . $cell . '</th>';
                }
                $tableHtml .= '</tr></thead><tbody>';
                $isHeader = false;
            } else {
                $tableHtml .= '<tr>';
                foreach ($cells as $cell) {
                    $tableHtml .= '<td>' . $cell . '</td>';
                }
                $tableHtml .= '</tr>';
            }
        }
        
        $tableHtml .= '</tbody></table>';
        return $tableHtml;
    }, $html);
    
    // Absätze (doppelte Zeilenumbrüche)
    $html = preg_replace('/\n\n+/', '</p><p>', $html);
    $html = '<p>' . $html . '</p>';
    
    // Einzelne Zeilenumbrüche
    $html = str_replace("\n", '<br>', $html);
    
    // Leere Absätze entfernen
    $html = preg_replace('/<p>\s*<\/p>/', '', $html);
    $html = preg_replace('/<p>\s*<br>\s*<\/p>/', '', $html);
    
    // Absätze um Block-Elemente entfernen
    $html = preg_replace('/<p>\s*(<h[1-6]>)/', '$1', $html);
    $html = preg_replace('/(<\/h[1-6]>)\s*<\/p>/', '$1', $html);
    $html = preg_replace('/<p>\s*(<hr>)/', '$1', $html);
    $html = preg_replace('/(<hr>)\s*<\/p>/', '$1', $html);
    $html = preg_replace('/<p>\s*(<table>)/', '$1', $html);
    $html = preg_replace('/(<\/table>)\s*<\/p>/', '$1', $html);
    $html = preg_replace('/<p>\s*(<blockquote>)/', '$1', $html);
    $html = preg_replace('/(<\/blockquote>)\s*<\/p>/', '$1', $html);
    
    // Escaped Pipes wiederherstellen
    $html = str_replace('{{PIPE}}', '|', $html);
    
    // Übrige Artefakte bereinigen
    $html = preg_replace('/<p><br><\/p>/', '', $html);
    $html = preg_replace('/(<br>)+<\/p>/', '</p>', $html);
    $html = preg_replace('/<p>(<br>)+/', '<p>', $html);
    
    return $html;
}

/**
 * Lädt das HTML-Template und ersetzt Platzhalter
 */
function renderTemplate(array $variables): string
{
    $template = file_get_contents(__DIR__ . '/index.html');
    
    foreach ($variables as $key => $value) {
        $template = str_replace('{{' . $key . '}}', (string) $value, $template);
    }
    
    return $template;
}

/**
 * Generiert die Navigation als HTML
 */
function renderNavItems(array $products, ?string $selectedProduct): string
{
    $html = '';
    foreach ($products as $key => $product) {
        $activeClass = $selectedProduct === $key ? 'active' : '';
        $title = htmlspecialchars($product['title']);
        $artikelnummer = htmlspecialchars($key);
        $url = '?product=' . urlencode($key);
        
        $html .= <<<HTML
                <li class="nav-item {$activeClass}">
                    <a href="{$url}">
                        {$title}
                        <span class="artikelnummer">{$artikelnummer}</span>
                    </a>
                </li>
HTML;
    }
    return $html;
}

/**
 * Generiert den Hauptinhalt
 */
function renderContent(?string $productContent, array $products): string
{
    if ($productContent) {
        return markdownToHtml($productContent);
    }
    
    $warning = '';
    if (empty($products)) {
        $warning = <<<HTML
                        <p style="margin-top: 20px; color: var(--accent);">
                            ⚠️ Keine Markdown-Dateien im Ordner <code>/products</code> gefunden.<br>
                            Führe zuerst <code>php generation/gen-markdown.php</code> aus.
                        </p>
HTML;
    }
    
    return <<<HTML
                    <div class="welcome">
                        <h1>Willkommen</h1>
                        <p>Wähle ein Produkt aus der Navigation, um die Details anzuzeigen.</p>
                        {$warning}
                    </div>
HTML;
}

// --- Hauptlogik ---

// Statische Dateien direkt ausliefern
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$requestPath = parse_url($requestUri, PHP_URL_PATH);

if ($requestPath === '/style.css') {
    header('Content-Type: text/css');
    readfile(__DIR__ . '/style.css');
    exit;
}

$products = getProductFiles($productsDir);
$selectedProduct = $_GET['product'] ?? null;
$productContent = null;

if ($selectedProduct && isset($products[$selectedProduct])) {
    $productContent = file_get_contents($products[$selectedProduct]['file']);
}

// Template rendern
$pageTitle = $selectedProduct && isset($products[$selectedProduct]) 
    ? ' - ' . htmlspecialchars($products[$selectedProduct]['title'] ?? '') 
    : '';

echo renderTemplate([
    'pageTitle' => $pageTitle,
    'productCount' => count($products),
    'navItems' => renderNavItems($products, $selectedProduct),
    'content' => renderContent($productContent, $products),
]);

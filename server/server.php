<?php

declare(strict_types=1);

/**
 * Produkt-Viewer für KI-generierte Produktbeschreibungen
 *
 * Parst Produkt-Markdown-Dateien in semantische Sektionen und rendert
 * sie als Web-Viewer mit Sidebar-Navigation, Produktkarten, Key-Value-Grids
 * und klappbaren Detailbereichen.
 *
 * Start: php -S localhost:8000 -t server server/server.php
 */

// ============================================================================
// DATENMODELLE
// ============================================================================

/**
 * Produktabschnitte der Markdown-Dateien
 */
enum Section: string
{
    case Produktinformationen = 'Produktinformationen';
    case Produktbeschreibung = 'Produktbeschreibung';
    case SEO = 'SEO';
    case TechnischeSpezifikationen = 'Technische Spezifikationen';
    case Eigenschaften = 'Eigenschaften';
}

/**
 * Unveränderliches Produkt-Datenobjekt
 *
 * Enthält die geparsten Daten einer Produkt-Markdown-Datei.
 */
readonly class Product
{
    /**
     * @param string $artikelnummer Eindeutige Artikelnummer (z.B. BM-100001)
     * @param string $title Produkttitel aus der ersten Überschrift
     * @param string $shortDescription Kurzbeschreibung aus dem Blockquote
     * @param array<string, string> $sections Markdown-Inhalt pro Sektion
     */
    public function __construct(
        public string $artikelnummer,
        public string $title,
        public string $shortDescription,
        public array $sections,
    ) {}
}

// ============================================================================
// KONFIGURATION
// ============================================================================

/** Verzeichnis mit den Produkt-Markdown-Dateien */
const PRODUCTS_DIR = __DIR__ . '/../products';

// ============================================================================
// DATENVERARBEITUNG
// ============================================================================

/**
 * Teilt eine Markdown-Datei in benannte Sektionen auf
 *
 * Erkennt `## Überschrift`-Zeilen als Sektionsgrenzen und ordnet den Inhalt
 * dem passenden Section-Enum zu. Unbekannte Sektionen werden ignoriert.
 *
 * @param string $markdown Vollständiger Markdown-Inhalt
 * @return array<string, string> Sektionsname → Markdown-Inhalt
 */
function extractSections(string $markdown): array
{
    $sectionMap = array_column(Section::cases(), null, 'value');
    $sections = [];
    $currentSection = null;
    $currentContent = [];

    foreach (explode("\n", $markdown) as $line) {
        if (preg_match('/^##\s+(.+)$/', $line, $matches)) {
            // Vorherige Sektion speichern
            if ($currentSection !== null) {
                $sections[$currentSection] = implode("\n", $currentContent) |> trim(...);
            }
            $sectionName = trim($matches[1]);
            $currentSection = isset($sectionMap[$sectionName]) ? $sectionName : null;
            $currentContent = [];
        } elseif ($currentSection !== null) {
            $currentContent[] = $line;
        }
    }

    // Letzte Sektion speichern
    if ($currentSection !== null) {
        $sections[$currentSection] = implode("\n", $currentContent) |> trim(...);
    }

    // Trennlinien (---) am Ende jeder Sektion entfernen
    return array_map(fn(string $s) => preg_replace('/\n?---\s*$/', '', $s) |> trim(...), $sections);
}

/**
 * Parst eine Produkt-Markdown-Datei in ein Product-Objekt
 *
 * Extrahiert Titel (erste H1-Überschrift), Kurzbeschreibung (erstes Blockquote)
 * und alle Sektionen. Nutzt den Pipe-Operator für die Textbereinigung.
 *
 * @param string $filePath Absoluter Pfad zur Markdown-Datei
 * @return Product Geparste Produktdaten
 */
function parseProductFile(string $filePath): Product
{
    $content = file_get_contents($filePath);
    $filename = basename($filePath, '.md');

    // Titel: erste H1-Überschrift
    $title = preg_match('/^#\s+(.+)$/m', $content, $m) ? $m[1] : $filename;

    // Kurzbeschreibung: erstes Blockquote
    $shortDescription = preg_match('/^>\s*(.+)$/m', $content, $m)
        ? $m[1] |> trim(...)
        : '';

    return new Product(
        artikelnummer: $filename,
        title: $title,
        shortDescription: $shortDescription,
        sections: extractSections($content),
    );
}

/**
 * Lädt alle Produkt-Markdown-Dateien und gibt sie sortiert zurück
 *
 * @return array<string, Product> Artikelnummer → Product, alphabetisch sortiert
 */
function loadProducts(): array
{
    if (!is_dir(PRODUCTS_DIR)) {
        return [];
    }

    $products = [];

    foreach (glob(PRODUCTS_DIR . '/*.md') as $file) {
        $product = parseProductFile($file);
        $products[$product->artikelnummer] = $product;
    }

    ksort($products);
    return $products;
}

// ============================================================================
// MARKDOWN-RENDERING
// ============================================================================

/**
 * Konvertiert allgemeines Markdown zu HTML
 *
 * Nutzt den Pipe-Operator für eine Kette von Regex-Transformationen.
 * Unterstützt Überschriften, Fett/Kursiv, Code, Blockquotes, horizontale Linien
 * und Absätze. Tabellen werden separat in renderKeyValueGrid() behandelt.
 *
 * @param string $markdown Markdown-Text
 * @return string HTML-Ausgabe
 */
function markdownToHtml(string $markdown): string
{
    return $markdown
        // Escaped Pipes temporär ersetzen
        |> (fn(string $t) => str_replace('\\|', '{{PIPE}}', $t))
        // HTML-Sonderzeichen escapen
        |> (fn(string $t) => htmlspecialchars($t, ENT_QUOTES, 'UTF-8'))
        // Überschriften (h6 → h1 Reihenfolge für korrekte Erkennung)
        |> (fn(string $t) => preg_replace('/^######\s+(.+)$/m', '<h6>$1</h6>', $t))
        |> (fn(string $t) => preg_replace('/^#####\s+(.+)$/m', '<h5>$1</h5>', $t))
        |> (fn(string $t) => preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $t))
        |> (fn(string $t) => preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $t))
        |> (fn(string $t) => preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $t))
        |> (fn(string $t) => preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $t))
        // Blockquotes
        |> (fn(string $t) => preg_replace('/^&gt;\s*(.*)$/m', '<blockquote>$1</blockquote>', $t))
        |> (fn(string $t) => preg_replace('/<\/blockquote>\s*<blockquote>/s', '<br>', $t))
        // Horizontale Linie
        |> (fn(string $t) => preg_replace('/^---$/m', '<hr>', $t))
        // Fett, Kursiv, Code
        |> (fn(string $t) => preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $t))
        |> (fn(string $t) => preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t))
        |> (fn(string $t) => preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $t))
        |> (fn(string $t) => preg_replace('/`(.+?)`/', '<code>$1</code>', $t))
        // Absätze und Zeilenumbrüche
        |> (fn(string $t) => preg_replace('/\n\n+/', '</p><p>', $t))
        |> (fn(string $t) => '<p>' . $t . '</p>')
        |> (fn(string $t) => str_replace("\n", '<br>', $t))
        // Leere Absätze und Artefakte bereinigen
        |> (fn(string $t) => preg_replace('/<p>\s*<\/p>/', '', $t))
        |> (fn(string $t) => preg_replace('/<p>\s*<br>\s*<\/p>/', '', $t))
        // Block-Elemente aus Absätzen befreien
        |> (fn(string $t) => preg_replace('/<p>\s*(<(?:h[1-6]|hr|table|blockquote)>)/', '$1', $t))
        |> (fn(string $t) => preg_replace('/(<\/(?:h[1-6]|hr|table|blockquote)>)\s*<\/p>/', '$1', $t))
        |> (fn(string $t) => preg_replace('/<p>(<br>)+/', '<p>', $t))
        |> (fn(string $t) => preg_replace('/(<br>)+<\/p>/', '</p>', $t))
        |> (fn(string $t) => preg_replace('/<p><br><\/p>/', '', $t))
        // Escaped Pipes wiederherstellen
        |> (fn(string $t) => str_replace('{{PIPE}}', '|', $t));
}

/**
 * Rendert eine Markdown-Tabelle als Key-Value-Grid
 *
 * Parst Tabellenzeilen im Format `| Key | Value |` und erzeugt ein
 * zweispaltiges HTML-Grid mit gedämpftem Label und prominentem Wert.
 *
 * @param string $tableMarkdown Markdown-Tabelle (mit Header und Separator)
 * @return string HTML Key-Value-Grid
 */
function renderKeyValueGrid(string $tableMarkdown): string
{
    $rows = explode("\n", trim($tableMarkdown));
    $html = '<div class="kv-grid">';
    $isFirstDataRow = true;

    foreach ($rows as $row) {
        $row = trim($row);
        // Leere Zeilen und Separator überspringen
        if (empty($row) || preg_match('/^\|[\s\-:|]+\|$/', $row)) {
            continue;
        }
        // Header-Zeile überspringen (erste Datenzeile vor Separator)
        if ($isFirstDataRow) {
            $isFirstDataRow = false;
            continue;
        }
        // Nur Zeilen mit mindestens 2 Zellen verarbeiten
        $cells = explode('|', trim($row, '|'));
        if (count($cells) < 2) {
            continue;
        }

        $key = trim($cells[0]) |> (fn(string $t) => strip_tags($t))
            |> (fn(string $t) => preg_replace('/\*\*(.+?)\*\*/', '$1', $t))
            |> trim(...);
        $value = trim($cells[1]) |> (fn(string $t) => preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t))
            |> trim(...);

        // Leere Keys überspringen (z.B. headerlose Info-Tabellen)
        if ($key === '' && str_contains($value, '<strong>')) {
            continue;
        }

        if ($key === '') {
            continue;
        }

        $safeKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');

        $html .= <<<HTML
            <div class="kv-row">
                <span class="kv-label">{$safeKey}</span>
                <span class="kv-value">{$value}</span>
            </div>
        HTML;
    }

    $html .= '</div>';
    return $html;
}

/**
 * Rendert die Produktinfo-Tabelle (Artikelnummer, Kategorie, Preis)
 *
 * Spezialbehandlung für die headerlose Tabelle im Produktinformationen-Abschnitt.
 * Erzeugt ein kompaktes Info-Banner statt eines Key-Value-Grids.
 *
 * @param string $tableMarkdown Markdown der Produktinfo-Tabelle
 * @return string HTML-Info-Banner
 */
function renderProductInfoBanner(string $tableMarkdown): string
{
    $rows = explode("\n", trim($tableMarkdown));
    $items = [];

    foreach ($rows as $row) {
        $row = trim($row);
        if (empty($row) || preg_match('/^\|[\s\-:|]+\|$/', $row)) {
            continue;
        }

        $cells = explode('|', trim($row, '|'));
        if (count($cells) < 2) {
            continue;
        }

        $key = trim($cells[0]) |> (fn(string $t) => preg_replace('/\*\*(.+?)\*\*/', '$1', $t)) |> trim(...);
        $value = trim($cells[1]) |> (fn(string $t) => preg_replace('/\*\*(.+?)\*\*/', '$1', $t)) |> trim(...);

        if ($key !== '' && $value !== '') {
            $safeKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            $items[] = "<span class=\"info-item\"><span class=\"info-label\">{$safeKey}</span> {$safeValue}</span>";
        }
    }

    return '<div class="product-info-banner">' . implode('', $items) . '</div>';
}

/**
 * Rendert den gesamten Produktinhalt sektionsbasiert
 *
 * Jede Sektion erhält eine individuelle HTML-Behandlung:
 * - Produktinformationen → Info-Banner
 * - Produktbeschreibung → Prose-Block mit markdownToHtml()
 * - SEO → Kompakte Card mit Meta-Description
 * - Technische Spezifikationen → Klappbares Key-Value-Grid
 * - Eigenschaften → Klappbares Key-Value-Grid
 *
 * @param Product $product Das zu rendernde Produkt
 * @return string Vollständiges HTML des Produktinhalts
 */
function renderProductContent(Product $product): string
{
    $html = '';

    // Titel
    $safeTitle = htmlspecialchars($product->title, ENT_QUOTES, 'UTF-8');
    $html .= "<h1>{$safeTitle}</h1>";

    // Kurzbeschreibung als Hero-Banner
    if ($product->shortDescription !== '') {
        $safeShort = htmlspecialchars($product->shortDescription, ENT_QUOTES, 'UTF-8');
        $html .= "<div class=\"hero-banner\"><p>{$safeShort}</p></div>";
    }

    // Produktinformationen als Info-Banner
    $infoSection = $product->sections[Section::Produktinformationen->value] ?? '';
    if ($infoSection !== '') {
        $html .= renderProductInfoBanner($infoSection);
    }

    // Produktbeschreibung als Prose
    $descSection = $product->sections[Section::Produktbeschreibung->value] ?? '';
    if ($descSection !== '') {
        $html .= '<section class="card"><h2>Produktbeschreibung</h2>';
        $html .= '<div class="prose">' . markdownToHtml($descSection) . '</div>';
        $html .= '</section>';
    }

    // SEO als kompakte Card
    $seoSection = $product->sections[Section::SEO->value] ?? '';
    if ($seoSection !== '') {
        // Meta-Description extrahieren (vor dem ---Separator stoppen)
        $metaDesc = preg_match('/Meta-Description:\*?\*?\s*\n\n?(.+?)(?:\n---|\z)/s', $seoSection, $m)
            ? trim($m[1])
            : trim($seoSection);
        $safeMetaDesc = htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8');
        $charCount = mb_strlen($metaDesc);

        $html .= <<<HTML
            <section class="card card-seo">
                <h2>SEO</h2>
                <div class="meta-description">
                    <span class="meta-label">Meta-Description</span>
                    <span class="meta-count">{$charCount} Zeichen</span>
                </div>
                <p class="meta-text">{$safeMetaDesc}</p>
            </section>
        HTML;
    }

    // Technische Spezifikationen — klappbar
    $specSection = $product->sections[Section::TechnischeSpezifikationen->value] ?? '';
    if ($specSection !== '') {
        $specGrid = renderKeyValueGrid($specSection);
        $html .= <<<HTML
            <details class="card" open>
                <summary><h2>Technische Spezifikationen</h2></summary>
                {$specGrid}
            </details>
        HTML;
    }

    // Eigenschaften — klappbar
    $propsSection = $product->sections[Section::Eigenschaften->value] ?? '';
    if ($propsSection !== '') {
        $propsGrid = renderKeyValueGrid($propsSection);
        $html .= <<<HTML
            <details class="card" open>
                <summary><h2>Eigenschaften</h2></summary>
                {$propsGrid}
            </details>
        HTML;
    }

    return $html;
}

// ============================================================================
// TEMPLATE-RENDERING
// ============================================================================

/**
 * Lädt das HTML-Template und ersetzt Platzhalter
 *
 * @param array<string, string> $variables Platzhalter → Wert
 * @return string Gerendertes HTML
 */
function renderTemplate(array $variables): string
{
    $template = file_get_contents(__DIR__ . '/index.html');

    return array_reduce(
        array_keys($variables),
        fn(string $html, string $key) => str_replace('{{' . $key . '}}', $variables[$key], $html),
        $template,
    );
}

/**
 * Generiert die Sidebar-Navigation als HTML
 *
 * @param array<string, Product> $products Alle geladenen Produkte
 * @param string|null $selectedId Artikelnummer des ausgewählten Produkts
 * @return string HTML der Navigationseinträge
 */
function renderNavItems(array $products, ?string $selectedId): string
{
    $html = '';

    foreach ($products as $product) {
        $isActive = $selectedId === $product->artikelnummer;
        $activeClass = $isActive ? 'active' : '';
        $safeTitle = htmlspecialchars($product->title, ENT_QUOTES, 'UTF-8');
        $safeNr = htmlspecialchars($product->artikelnummer, ENT_QUOTES, 'UTF-8');
        $url = '?product=' . urlencode($product->artikelnummer);

        $html .= <<<HTML
            <li class="nav-item {$activeClass}">
                <a href="{$url}">
                    <span class="nav-title">{$safeTitle}</span>
                    <span class="nav-artikelnummer">{$safeNr}</span>
                </a>
            </li>
        HTML;
    }

    return $html;
}

/**
 * Generiert den Hauptinhalt (Willkommensseite oder Produktansicht)
 *
 * @param array<string, Product> $products Alle geladenen Produkte
 * @param string|null $selectedId Artikelnummer des ausgewählten Produkts
 * @return string HTML-Inhalt
 */
function renderMainContent(array $products, ?string $selectedId): string
{
    // Ausgewähltes Produkt rendern
    if ($selectedId !== null && isset($products[$selectedId])) {
        return renderProductContent($products[$selectedId]);
    }

    // Willkommensseite
    $warning = empty($products)
        ? '<p class="warning">Keine Markdown-Dateien im Ordner <code>/products</code> gefunden. '
          . 'Führe zuerst <code>php generation/gen-markdown.php</code> aus.</p>'
        : '';

    return <<<HTML
        <div class="welcome">
            <h1>Produkt-Viewer</h1>
            <p>Wähle ein Produkt aus der Navigation, um die Details anzuzeigen.</p>
            {$warning}
        </div>
    HTML;
}

// ============================================================================
// ROUTING & HAUPTLOGIK
// ============================================================================

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

// Statische Dateien ausliefern
$served = match ($requestPath) {
    '/style.css' => (function () {
        header('Content-Type: text/css');
        readfile(__DIR__ . '/style.css');
        return true;
    })(),
    default => false,
};

if ($served) {
    exit;
}

// Produkte laden und Seite rendern
$products = loadProducts();
$selectedId = $_GET['product'] ?? null;

$pageTitle = match (true) {
    $selectedId !== null && isset($products[$selectedId]) => ' — ' . $products[$selectedId]->title,
    default => '',
};

echo renderTemplate([
    'pageTitle' => htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'),
    'productCount' => (string) count($products),
    'navItems' => renderNavItems($products, $selectedId),
    'content' => renderMainContent($products, $selectedId),
]);

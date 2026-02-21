# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

AI-powered product description generator for hardware stores (German: "Baumärkte"). Takes raw product JSON data, processes it through a 5-stage AI pipeline via LM Studio (OpenAI-compatible local API), and outputs SEO-optimized German product descriptions. Includes a PHP web viewer for browsing results.

## Commands

```bash
# Generate product descriptions (requires LM Studio running on localhost:1234)
php generation/gen-desc.php

# Export descriptions to individual markdown files
php generation/gen-markdown.php

# Start web viewer at http://localhost:8000
php -S localhost:8000 -t server server/server.php

# Clear cache to force full regeneration
rm generation/generation_cache.json
```

## Architecture

### Pipeline (`generation/gen-desc.php`)
5-stage sequential AI pipeline per product, each stage refining the previous output:

1. **Fact Extraction** (temp 0.15) — Structured base description from raw specs
2. **Benefit Argumentation** (temp 0.20) — Technical specs → customer benefits
3. **SEO Optimization** (temp 0.15) — Keyword placement, readability, Flesch index
4. **Quality Control** (temp 0.10) — Fact validation against source data
5. **Short Texts** (temp 0.15) — Short description (80-130 chars) + meta description (140-155 chars)

Each stage uses a prompt template from `generation/prompts/` with `{{variable}}` placeholders resolved by `loadPrompt()` in `inc.php`.

### Key Files
- `generation/inc.php` — Shared utilities: `callAI()` (CURL + retry logic), `loadPrompt()`, `cleanMarkdown()`, `formatProductContext()`, logging, caching
- `generation/gen-desc.conf.php` — Runtime config (API URL, model, token limits, retry settings). Copy from `.conf.example.php`
- `generation/gen-markdown.conf.php` — Markdown export config. Copy from `.conf.example.php`
- `server/server.php` — Router + markdown-to-HTML converter + template renderer
- `test-products.json` — Input product data (artikelnummer, technische_spezifikationen, eigenschaften)

### Data Flow
```
test-products.json → gen-desc.php → products_with_descriptions.json → gen-markdown.php → products/*.md → server.php (viewer)
```

### Caching
`generation_cache.json` stores completed products keyed by `artikelnummer`. The pipeline skips cached products on re-run, allowing safe interruption/resumption.

## Conventions

- **Language:** PHP 8.0+ with `declare(strict_types=1)`, functional style (no classes)
- **All generated content is in German** — descriptions, prompts, short texts, meta descriptions
- **German number formatting:** comma as decimal separator (2,5), period as thousands (10.000)
- **Config pattern:** `.conf.example.php` is tracked, `.conf.php` is gitignored — always update both when changing config structure
- **Functions:** camelCase, documented with PHPDoc
- **Files:** kebab-case naming
- **Prompt templates:** Plain text files with `{{variableName}}` placeholders
- **Fact validation:** Pipeline checks that critical specs (Spannung, Leistung, Drehmoment, etc.) appear in the final output; tolerates 1-2 missing
- **Output constraints:** Final descriptions 650-850 characters, 3 paragraphs, plain text (no markdown formatting)

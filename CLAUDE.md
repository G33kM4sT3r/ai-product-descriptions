# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

AI-powered product description generator for hardware stores (German: "Baumärkte"). Takes raw product JSON data, processes it through a 5-stage AI pipeline, and outputs SEO-optimized German product descriptions. Supports LM Studio (local), OpenAI, and Anthropic APIs. Includes a PHP web viewer for browsing results.

## Commands

```bash
# Full pipeline (pre-flight checks + generate + export + stats)
./scripts/run.sh [--force] [--serve]

# Individual steps
./scripts/generate.sh [--force]    # Generate descriptions (--force clears cache)
./scripts/export.sh                # Export to markdown files
./scripts/serve.sh [PORT]          # Start viewer (default: 8000)
./scripts/clear-cache.sh [--yes]   # Clear cache (with confirmation)
./scripts/show-stats.sh            # Show last run statistics

# Direct PHP commands (still work, but prefer shell scripts)
php generation/gen-desc.php
php generation/gen-markdown.php
php -S localhost:8000 -t server server/server.php
```

## Architecture

### Pipeline (`generation/gen-desc.php`)
5-stage sequential AI pipeline per product, each stage refining the previous output. Supports LM Studio, OpenAI, and Anthropic APIs with automatic provider capability detection.

| Stage | Name | Default Temp | Purpose |
|-------|------|:------------:|---------|
| 1 | Faktenextraktion | 0.10 | Structured base description from raw specs |
| 2 | Nutzenargumentation | 0.35 | Technical specs → customer benefits |
| 3 | SEO-Optimierung | 0.15 | Keyword placement, readability |
| 4 | Qualitätskontrolle | 0.05 | Fact validation against source data |
| 5 | Kurztexte | 0.10 | Short description + meta description |

Each stage uses a prompt template from `generation/prompts/` with `{{variable}}` placeholders resolved by `loadPrompt()` in `inc.php`. Per-stage model, temperature, and token limits are configurable in `gen-desc.conf.php`.

**Validation with correction retries:** Each stage validates output against criteria (paragraph count, word/character count, fact presence). On violation, a targeted correction prompt is sent — up to 2 retries per stage.

### Key Files
- `generation/inc.php` — Provider abstraction, `callAI()`/`callAIWithValidation()`, validation framework, prompt loading, caching, logging
- `generation/gen-desc.php` — Pipeline main loop with fact validation, hash-based cache, tree logging
- `generation/gen-desc.conf.php` — Runtime config (provider, per-stage model/temperature/tokens). Copy from `.conf.example.php`
- `generation/gen-markdown.conf.php` — Markdown export config. Copy from `.conf.example.php`
- `generation/prompts/*.prompt` — Prompt templates (AUFGABE → EINGABE → REGELN → FORMAT → BEISPIEL structure)
- `scripts/*.sh` — Shell scripts for orchestration, logging, pre-flight checks
- `server/server.php` — Router + markdown-to-HTML converter + template renderer
- `test-products.json` — Input product data (artikelnummer, technische_spezifikationen, eigenschaften)

### Data Flow
```
test-products.json → gen-desc.php → products_with_descriptions.json → gen-markdown.php → products/*.md → server.php (viewer)
```

### Caching
`generation_cache.json` stores completed products keyed by `artikelnummer` with an MD5 hash of input data (`_inputHash`). On re-run:
- Unchanged products are skipped (hash match)
- Modified products are automatically regenerated (hash mismatch)
- Safe interruption/resumption — already generated products persist

## Conventions

- **Language:** PHP 8.5+ with `declare(strict_types=1)`, functional style (no classes). Uses pipe operator (`|>`) in `cleanMarkdown()`, `array_first()`/`array_last()` in `parseShortTextsValidated()`, `array_reduce()` in `loadPrompt()`
- **All generated content is in German** — descriptions, prompts, short texts, meta descriptions
- **German umlauts:** All prompts, comments, and user-facing strings use proper umlauts (ä, ö, ü, ß) — never ASCII substitutes (ae, oe, ue). Code identifiers (function names, variable names, array keys) stay ASCII
- **Worktrees:** `.worktrees/` directory (gitignored) for isolated feature work
- **German number formatting:** comma as decimal separator (2,5), period as thousands (10.000)
- **Config pattern:** `.conf.example.php` is tracked, `.conf.php` is gitignored — always update both when changing config structure
- **Functions:** camelCase, documented with PHPDoc
- **Files:** kebab-case naming
- **Prompt templates:** Plain text files with `{{variableName}}` placeholders, structure: AUFGABE → EINGABE → REGELN → FORMAT → BEISPIEL
- **Fact validation:** Pipeline checks that critical specs (Spannung, Leistung, Drehmoment, etc.) appear in the final output; category-specific keys extend the global list; tolerates 1-2 missing
- **Output constraints:** Final descriptions 650-850 characters, 3 paragraphs, plain text (no markdown formatting)
- **Shell scripts:** `set -euo pipefail`, colored output functions (log/error/success/warn), header comments with usage/options/prerequisites
- **Provider support:** LM Studio (local, default), OpenAI, Anthropic — auto-detected capabilities for system role and JSON schema

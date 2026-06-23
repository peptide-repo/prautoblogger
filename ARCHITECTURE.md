# PRAutoBlogger — Architecture

> **Cross-app context:** decisions that affect multiple plugins (Cloudflare AI Gateway routing, OpenRouter account sharing, the interface pattern, image-generation stack, social distributor choice) are recorded in `Peptide Repo CTO/docs/engineering/decisions/`. The incident runbook for cross-app failure modes is at `Peptide Repo CTO/docs/engineering/INCIDENT-RUNBOOK.md`. Engineer PRAutoBlogger should read both before making decisions that cross plugin boundaries.

> **App-local decisions:** choices that affect only this plugin are recorded in [`docs/adr/`](docs/adr/README.md) — a per-repo numbered log (0001, 0002, …). Read `docs/adr/README.md` for the index. The routing rule and required template live centrally in `Peptide Repo CTO/docs/engineering/ADR-PROCESS.md`.

PRAutoBlogger is a WordPress plugin that monitors social media (starting with Reddit) for recurring questions, complaints, and comparisons in a configured niche, uses LLM agents (via OpenRouter) to generate high-quality blog articles from those insights, runs them through a chief editor agent for quality review, and auto-publishes them on a configurable daily schedule. All collected data and generation metrics are stored for a self-improvement feedback loop.

---

## Data Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    WP-Cron / Action Scheduler                          │
│                    (Daily trigger, configurable)                        │
└────────────┬────────────────────────────────────────────────────────────┘
             │
             ▼
┌─────────────────────────┐
│  1. Source Collector     │  Pulls data from enabled sources:
│  (Reddit + LLM Research)│  - Reddit RSS/Atom (primary) / .json (fallback)
│                         │  - LLM Deep Research (reasoning model)
│                         │  Stores raw data in `ab_source_data` table
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  2. Content Analyzer    │  LLM call (cheap model) to detect:
│  (Analysis Agent)       │  - Recurring questions
│                         │  - Complaints / pain points
│                         │  - Product comparisons
│                         │  Stores analysis in `ab_analysis_results` table
│                         │  Outputs ranked article ideas
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  3. Idea Scorer &       │  Deduplicates against existing posts
│     Deduplicator        │  Scores ideas by relevance, frequency, freshness
│                         │  Picks top N ideas (N = daily article target)
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  4. Writer Agent        │  Configurable pipeline:
│  (Content Generator)    │  Single-pass: one LLM call → complete article
│                         │  Multi-step: outline → draft → polish
│                         │  Uses quality model via OpenRouter
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  5. Chief Editor Agent  │  LLM-powered editorial review:
│                         │  - Checks quality, accuracy, tone
│                         │  - Checks SEO (title, headings, keyword density)
│                         │  - Can request rewrites or approve
│                         │  - Approved → publish; Rejected → flag for human
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  6. Publisher           │  Creates/updates WordPress post
│                         │  Sets categories, tags, featured image
│                         │  Stores generation metadata in post_meta
│                         │  Logs cost/tokens in `prab_generation_log` table
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  6b. Image Pipeline     │  Generates two A/B images in parallel:
│  (optional, if enabled) │  - Image A: article-driven (featured)
│                         │  - Image B: source-driven (post meta)
│                         │  Provider derived from the saved Image Model
│                         │  via Image_Model_Registry::provider_for().
│                         │  NSFW-blocked slots (CF error code 3030) are
│                         │  retried once with a rule-based fallback
│                         │  prompt via Image_NSFW_Retry; a second block
│                         │  logs WARNING and publishes without that image.
│                         │  v0.17.0: results then pass through the local
│                         │  deterministic Image_Composer (corner mark +
│                         │  branded OG/square variants for Image A; mark
│                         │  only for Image B) with a capability ladder
│                         │  (imagick → GD resize-only → pass-through).
│                         │  Sideloads to media library, logs costs.
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│  7. Metrics Collector   │  Tracks post-publish performance:
│  (Separate cron job)    │  - WordPress native (views, comments)
│                         │  - GA4 (pageviews, bounce rate, time on page)
│                         │  - Composite "content score"
│                         │  Feeds back into Analysis step for self-improvement
└─────────────────────────┘
```

---

## File Tree

```
prautoblogger/
├── prautoblogger.php                  # Plugin bootstrap — minimal, only hook registration
├── uninstall.php                      # Clean removal of ALL plugin data
├── readme.txt                         # WordPress.org standard readme
├── composer.json                      # Autoloading (PSR-4) and dependencies
├── ARCHITECTURE.md                    # This file
├── CONVENTIONS.md                     # Naming patterns, extension guides
├── CHANGELOG.md                       # Semantic versioning changelog
│
├── assets/
│   ├── brand/                         # Vendored brand PNGs rasterized from peptide-repo-brand SVGs (composer overlays)
│   │   ├── logo-mark-small-56.png     # Corner mark, 1x
│   │   ├── logo-mark-small-112.png    # Corner mark / OG band logo, 2x
│   │   ├── logo-horizontal-reverse-128.png # Square footer lockup, 1x
│   │   └── logo-horizontal-reverse-256.png # Square footer lockup, 2x
│   ├── fonts/                         # Bundled OFL faces for deterministic text rendering
│   │   ├── Poppins-Bold.ttf           # Square-card caption face
│   │   ├── Poppins-SemiBold.ttf       # OG band caption face
│   │   └── OFL.txt                    # SIL Open Font License
│   ├── css/
│   │   ├── admin.css                  # Admin page styles (wp-admin conventions)
│   │   ├── board.css                  # Mission Brief board styles -- verdict chips, run-list, inspector (v0.19.0, v0.27.0)
│   │   ├── dossier.css                # Article dossier page styles: verdict pills, cost receipt, trace toggle (v0.19.2)
│   │   ├── dossier-edit.css           # M3 edit/re-run layer: chips, edit panel, image grid, spend warning (v0.20.0)
│   │   └── posts-widget.css           # Frontend posts widget styles (uses theme CSS vars)
│   └── js/
│       ├── admin.js                   # Admin page interactivity (vanilla JS / Alpine.js)
│       ├── board.js                   # Mission Brief board: AJAX poll, run-list render, inspector rail (v0.19.0, v0.27.0)
│       ├── dossier.js                 # Per-stage raw-trace toggle: aria-expanded / aria-controls (v0.19.2)
│       ├── dossier-edit.js            # M3 edit/re-run actions + stage-status polling (queued -> pickup -> result) (v0.20.0)
│       └── posts-widget.js            # React component for frontend post cards (wp.element)
│
├── includes/
│   ├── class-prautoblogger.php        # Main orchestrator — registers all hooks, delegates execution (split v0.19.2)
│   ├── class-db-migrations.php        # DB migration methods extracted from class-prautoblogger.php (v0.19.2 split, binding 1)
│   ├── class-pipeline-schema-installer.php # Pipeline substrate tables (prompts, run_sources, run_decisions, runs, run_stages, stage_inputs)
│   ├── class-migrate-prompt-seed-v0180.php # One-shot v0.18.0 prompt-registry seed migration
│   ├── class-executor.php             # Cron handlers, generation AJAX (delegates start + status to poller), model registry
│   ├── class-generation-status-poller.php # Generation AJAX handlers: status transient renewal, lock-age orphan detection (R2b/R3), abort_orphaned_run
│   ├── class-ajax-handlers.php        # Non-generation AJAX: images, models, test connections
│   ├── class-generation-lock.php      # DB-level atomic mutex for single-writer generation
│   ├── class-activator.php            # Activation: create DB tables, set defaults, schedule cron
│   ├── class-deactivator.php          # Deactivation: clear cron, cleanup transients
│   ├── class-autoloader.php           # PSR-4-style autoloader for plugin classes
│   │
│   ├── admin/
│   │   ├── class-board-page.php       # Mission Brief board -- primary landing screen (v0.19.0, v0.27.0)
│   │   ├── class-board-data-provider.php # Board orchestrator: run-list snapshot + stage dots (v0.19.0, v0.27.0)
│   │   ├── class-board-stage-dots.php       # Dot-rail enrichment: batched run_stages query per board section (M5, v0.27.0)
│   │   ├── class-board-gen-log-query.php # Board gen_log queries: Generating + Failed column raw DB reads (v0.19.0)
│   │   ├── class-dossier-page.php     # Article dossier admin page: options.php-parent hidden-page, priority 12, enqueue, nonce (v0.19.3)
│   │   ├── class-dossier-data-assembler.php # 5-query view-model builder: runs+stages+gen_log+decisions+meta (v0.19.2)
│   │   ├── class-dossier-gen-log-query.php  # Dossier gen_log queries: per-stage cost receipt + raw trace (v0.19.2)
│   │   ├── class-dossier-actions.php        # M3 AJAX: save fork / queue replay / queue rebuild / stage-status poll (v0.20.0)
│   │   ├── class-dossier-rerun-panel-data.php # M3 per-stage affordance + spend view model (v0.20.0)
│   │   ├── class-dossier-image-data.php     # F3: post's pipeline attachments via _prautoblogger_image_role (v0.20.0)
│   │   ├── class-admin-page.php       # Main settings page (tabbed SaaS-style UI)
│   │   ├── class-settings-fields.php  # Declarative settings: sections + core fields (API/Models/Content/Sources)
│   │   ├── class-settings-fields-extended.php # Operational fields: schedule, publishing, display, analytics, images
│   │   ├── class-image-model-registry.php # Static list of image generation models for the model picker
│   │   ├── class-admin-notices.php    # Onboarding notices, error alerts, budget warnings
│   │   ├── class-dashboard-widget.php # WP Dashboard widget showing generation status
│   │   ├── class-post-metabox.php     # Metabox on posts showing generation metadata
│   │   ├── class-metrics-page.php     # Admin page for cost dashboard and content scores
│   │   ├── class-ideas-browser.php    # Browse all analysis results (article ideas)
│   │   ├── class-review-queue.php     # Approve/edit/reject queue for generated drafts
│   │   ├── class-log-viewer.php       # Activity log viewer with level filtering
│   │   ├── class-gen-history-page.php # M4: Generation History hidden page (options.php parent, priority 14)
│   │   └── class-gen-history-query.php # M4: Paginated run list + per-run I/O extraction queries
│   │
│   ├── ajax/
│   │   ├── class-model-registry-refresh.php # AJAX: refresh OpenRouter model registry (manual trigger)
│   │   ├── class-pipeline-preview-handler.php # M3: assembled-instructions preview from last-run gen_log (v0.25.0)
│   │   ├── class-pipeline-history-handler.php # M3: prompt version list + LCS diff (history + diff actions) (v0.25.0)
│   │   ├── class-gen-run-io-handler.php # M4: per-run stage I/O drill-down (prautoblogger_gen_run_io) (v0.26.0)
│   │   └── class-board-inspector-handler.php # M5: per-run stage inspector AJAX (prautoblogger_board_inspector) (v0.27.0)
│   │
│   ├── core/
│   │   ├── class-scheduler.php        # WP-Cron / Action Scheduler job management
│   │   ├── class-source-collector.php # Orchestrates data collection from all sources
│   │   ├── class-content-analyzer.php # LLM-powered analysis of collected social data
│   │   ├── class-analysis-prompts.php # System/user prompt builders + performance context for analyzer
│   │   ├── class-idea-scorer.php      # Ranks and deduplicates article ideas (delegates to Semantic_Dedup)
│   │   ├── class-semantic-dedup.php   # Embedding-based cosine similarity dedup with keyword fallback
│   │   ├── class-content-generator.php# Writer agent — manages the generation pipeline
│   │   ├── class-content-prompts.php  # Builds all LLM prompts for content generation (system, stage, linking rules)
│   │   ├── class-peptide-linker.php   # Deterministic post-processor: hyperlinks peptide mentions to /peptides/ pages
│   │   ├── class-chief-editor.php     # Editor agent — LLM-powered editorial review
│   │   ├── class-publisher.php        # Creates WordPress posts from approved content
│   │   ├── class-post-assembler.php   # Post-creation helpers: taxonomy, log linking, images, sanitization
│   │   ├── class-image-pipeline.php   # Orchestrates A/B image generation (parallel via batch)
│   │   ├── class-image-prompt-builder.php # Generates visual prompts from article/source data
│   │   ├── class-image-template-filler.php # Fills the editorial style template's {{ topic_summary }} slot (v0.16.0)
│   │   ├── interface-image-composer.php    # Contract: provider bytes → featured-first variant list (v0.17.0)
│   │   ├── class-image-composer.php        # Composer orchestrator: cached capability probe + degradation ladder
│   │   ├── class-image-composer-imagick.php # Imagick renderer: corner mark, OG band, square card (WP-free)
│   │   ├── class-image-composer-canvas.php # Imagick primitives: smoke test, cover-crop, logo, deterministic PNG (WP-free)
│   │   ├── class-image-composer-editor.php # Resize-only rung via wp_get_image_editor (GD path)
│   │   ├── class-image-composer-layout.php # Geometry defaults + caption clamp (pure, WP-free)
│   │   ├── class-image-attacher.php        # Sideload + cost log + variant meta-linking + caption prepend
│   │   ├── class-image-media-sideloader.php # Imports images into WordPress media library
│   │   ├── class-cost-tracker.php     # Logs all API costs, enforces budget limits
│   │   ├── class-stage-display-map.php # Stage vocabulary: labels + default roles + prompt keys (old + new + image stages)
│   │   ├── class-prompt-registry.php  # Versioned prompt registry, read side (render, pins, self-healing fallback)
│   │   ├── class-prompt-registry-writer.php # Registry write side (create version, activate, seed) — versions immutable
│   │   ├── class-prompt-defaults.php  # Canonical v1 bodies: content.* + analysis.* (seed + fallback single source)
│   │   ├── class-prompt-defaults-editorial.php # Canonical v1 bodies: editor.* / research.system / image.* (composer seam)
│   │   ├── class-run-context.php      # Per-process active run id (set by Cost_Tracker::set_run_id)
│   │   ├── class-run-state.php        # runs-table ledger + lifecycle row (ceiling/reserved/settled, pins, status)
│   │   ├── class-cost-governor.php    # Per-run reserve-before-call enforcement (atomic conditional UPDATE)
│   │   ├── class-cost-ceiling-exception.php # Thrown on ceiling breach (run already halted)
│   │   ├── class-run-stage-state.php  # Per-run per-stage state machine: read API + thin write proxies (split v0.20.0)
│   │   ├── class-run-stage-writes.php # Pipeline-path writes (start/done/fail; done clears stale) — extracted v0.20.0
│   │   ├── class-run-stage-rerun-state.php # M3 operator-action mutations: restart/mark_stale/demote (v0.20.0)
│   │   ├── class-stage-input-store.php # INSERT-only stage_inputs store: edit forks + idea seeds (v0.20.0)
│   │   ├── class-rerun-eligibility.php # M3 policy gates: frozen posts, editable stages, chain order (v0.20.0)
│   │   ├── class-stage-replay.php     # M3 governed single-stage replay from a fork body (v0.20.0)
│   │   ├── class-rerun-executor.php   # M3 cron handlers: replay + rebuild jobs (v0.20.0)
│   │   ├── class-rerun-job-support.php # M3 queue/lock/budget/status-transient plumbing (v0.20.0)
│   │   ├── class-request-recorder.php # B1: process-scoped outbound request-body stash -> generation_log.request_json (v0.20.0)
│   │   ├── class-run-reaper.php       # Stuck-run sweep + audit-payload retention (rides the #19 cron)
│   │   ├── class-audit-writer.php     # run_sources / run_decisions insert layer
│   │   ├── class-pipeline-status.php  # Status-transient + summary helpers (extracted from runner/worker)
│   │   ├── class-logger.php           # Structured logging singleton (error/warning/info/debug)
│   │   ├── class-article-worker.php   # Single-article generation (content + edit + publish)
│   │   ├── class-pipeline-runner.php  # Orchestrates pipeline; chains per-article cron jobs
│   │   ├── class-ga4-client.php       # Google Analytics 4 API client (OAuth + Data API)
│   │   └── class-metrics-collector.php# Collects post performance data (WP + GA4)
│   │
│   ├── providers/
│   │   ├── interface-llm-provider.php    # Contract for any LLM provider
│   │   ├── class-open-router-provider.php # OpenRouter API implementation (chat completions)
│   │   ├── class-open-router-completion-guard.php # Empty-completion guard: warn on finish_reason=length, one reasoning-off retry, audited failure (v0.18.1)
│   │   ├── class-open-router-embedding-provider.php # Text embeddings for semantic dedup
│   │   ├── class-open-router-pricing.php  # Model pricing lookup and cost estimation
│   │   ├── interface-source-provider.php # Contract for any social media source
│   │   ├── class-reddit-json-client.php  # Reddit HTTP client — RSS (primary) + .json (fallback)
│   │   ├── class-llm-research-provider.php # LLM deep research source (reasoning models)
│   │   ├── class-reddit-provider.php     # Reddit data collection orchestrator (RSS primary)
│   │   ├── interface-image-provider.php  # Contract for any image generation provider (incl. batch)
│   │   ├── class-open-router-image-provider.php  # OpenRouter image gen (single + batch dispatch)
│   │   ├── class-open-router-image-batch.php     # Parallel curl_multi execution for batch image gen
│   │   ├── class-open-router-image-support.php   # API key, response parsing, retry/backoff helpers
│   │   ├── class-open-router-image-pricing.php   # Model resolution + per-image cost estimation
│   │   ├── class-open-router-config.php          # API base URL (direct vs AI Gateway)
│   │   ├── class-open-router-request-builder.php # Request body assembly + reasoning token cap/headroom (v0.18.1) + headers + Hostinger cURL auth workaround
│   │   ├── class-runware-image-provider.php     # Runware FLUX.1 (default v0.9.0+)
│   │   ├── class-runware-image-pricing.php      # FLUX schnell/dev cost table + resolver
│   │   ├── class-runware-image-support.php      # Key, response parsing, retry, dimension snap
│   │   ├── class-runware-image-batch.php        # True parallel curl_multi batch dispatcher
│   │   ├── class-runware-model-catalog.php      # Live model catalog sync: modelSearch pagination, cache v2, failure cooldown
│   │   ├── class-runware-catalog-fetcher.php    # HTTP fetch + normalize_models for Runware modelSearch (extracted for 300-line rule)
│   │   └── (new providers go here — see CONVENTIONS.md)
│   │
│   ├── services/
│   │   ├── interface-model-registry.php           # Contract for provider-specific model registries
│   │   ├── class-open-router-model-registry.php    # Fetches, caches, queries the OpenRouter model list
│   │   ├── class-open-router-model-normalizer.php  # Maps raw OpenRouter data → standardized record shape
│   │   └── (Phase 3: class-runware-model-registry.php)
│   │
│   ├── frontend/
│   │   ├── class-article-typography.php # Inline CSS for font, size, table borders on generated posts
│   │   └── class-posts-widget.php     # [prautoblogger_posts] shortcode + REST endpoint
│   │
│   └── models/
│       ├── class-source-data.php      # Value object: raw social media post/comment
│       ├── class-analysis-result.php  # Value object: analyzed topic with scores
│       ├── class-article-idea.php     # Value object: scored article idea
│       ├── class-content-request.php  # Value object: generation request with params
│       ├── class-editorial-review.php # Value object: editor verdict, scores, revised content
│       ├── class-generation-log.php   # Value object: log entry for a generation run
│       └── class-content-score.php    # Value object: composite performance score
│
├── templates/
│   └── admin/
│       ├── board-page.php             # Mission Brief board template (run-list + inspector layout) (v0.19.0, v0.27.0)
│       ├── dossier-page.php           # Article dossier: two-column layout, sidebar, stage sections (v0.19.2)
│       ├── dossier-stage-section.php  # Stage section partial: output, raw trace, M3 chips + rerun footer
│       ├── dossier-edit-panel.php     # M3 per-stage edit panel: message textareas, fork info, spend strip (v0.20.0)
│       ├── dossier-log-stage-section.php # F3 log-only stage sections incl. image attachment grid (v0.20.0)
│       ├── dossier-sidebar-cards.php  # M3 sidebar: run spend (guardrail 4) + models/pv consolidation (F2) (v0.20.0)
│       ├── dossier-stage-section.php  # Per-stage block: rendered output + raw-trace toggle + cost receipt (v0.19.2)
│       ├── metabox-dossier-link.php   # Slim post metabox: "View generation dossier →" link (v0.19.2)
│       ├── settings-page.php          # Settings page template (tabbed sidebar layout)
│       ├── metrics-page.php           # Metrics/cost dashboard template
│       ├── review-queue.php           # Review queue table template
│       ├── log-viewer.php             # Activity log viewer template
│       └── metabox-generation-info.php# Post metabox template (superseded by metabox-dossier-link.php in v0.19.2)
│
├── languages/
│   └── prautoblogger.pot              # i18n translation template
│
└── tests/
    ├── unit/                          # PHPUnit tests (mocked dependencies)
    └── integration/                   # WordPress integration tests
```

---

## Frontend Widget Data Flow

```
┌──────────────────────────────┐
│  WordPress Page/Post         │  Contains [prautoblogger_posts] shortcode
│  (e.g. Home page)            │
└────────────┬─────────────────┘
             │  Shortcode renders:
             │  1. <div id="prab-posts-root"></div>
             │  2. Enqueues posts-widget.js + posts-widget.css
             │  3. Passes config via wp_localize_script
             ▼
┌──────────────────────────────┐
│  React Component (wp.element)│  Mounts into #prab-posts-root
│  posts-widget.js             │  Shows loading skeleton on mount
└────────────┬─────────────────┘
             │  Fetches asynchronously
             ▼
┌──────────────────────────────┐
│  REST API Endpoint           │  GET /wp-json/prautoblogger/v1/posts
│  class-posts-widget.php      │  Queries published posts with
│                              │  _prautoblogger_generated = '1' meta
│                              │  Returns slim JSON (id, title, excerpt,
│                              │  url, date, category, image, word_count)
│                              │  5-minute Cache-Control header
└──────────────────────────────┘
```

**Shortcode Attributes**

| Attribute  | Default                          | Description             |
|------------|----------------------------------|-------------------------|
| `count`    | `6`                              | Posts to display (max 12) |
| `category` | (all)                            | Filter by category slug |
| `title`    | `"Latest Research & Insights"`   | Widget heading          |
| `subtitle` | `"Evidence-based articles..."`   | Widget subheading       |

**CSS Integration**

The widget CSS (`posts-widget.css`) uses the Peptide Starter theme's CSS custom properties (`--color-*`, `--spacing-*`, `--text-*`, `--radius-*`, `--transition-*`) with hardcoded fallbacks. This means:

- **On sites using Peptide Starter:** widget automatically adapts to light/dark mode via `data-theme`.
- **On other themes:** fallback values render a sensible dark-themed design.

---

## Background Generation Flow (v0.3.0)

The "Generate Now" button runs the pipeline in a background WP-Cron process to avoid
Hostinger's 120-second LiteSpeed connection timeout. The frontend polls for status.

```
┌──────────────────────────────┐
│  Admin clicks "Generate Now" │  admin.js sends AJAX to on_ajax_generate_now()
└────────────┬─────────────────┘
             │  Handler returns immediately (< 1 second):
             │  1. Writes "running" transient with started timestamp
             │  2. Schedules one-shot cron: prautoblogger_manual_generation
             │  3. Fires non-blocking loopback to wp-cron.php
             │  4. Returns JSON success to browser
             ▼
┌──────────────────────────────┐
│  admin.js starts polling     │  setInterval every 5 seconds
│  on_ajax_generation_status() │  Shows stage text + spinner
└────────────┬─────────────────┘
             │
             ▼ (separate PHP process)
┌──────────────────────────────┐
│  on_manual_generation()      │  WP-Cron fires this in a new request
│  (class-executor.php)        │  1. ignore_user_abort(true) — survives HTTP kill
│                              │  2. set_time_limit(300)
│                              │  3. Acquires PRAutoBlogger_Generation_Lock
│                              │  4. Runs PRAutoBlogger_Pipeline_Runner::run()
│                              │     (pipeline broadcasts stage updates to transient)
│                              │  5. Writes final result to transient
│                              │  6. Releases lock
└────────────┬─────────────────┘
             │
             ▼
┌──────────────────────────────┐
│  Poller detects completion   │  Reads transient: status = 'complete' or 'error'
│  Shows result message        │  Resets button to idle state
└──────────────────────────────┘
```

**Fallback detection (v0.18.3 R2b/R3):** The status transient is renewed on every stage
transition via `update_generation_stage()` (`Pipeline_Status::broadcast()`), so its TTL
always counts from the last activity rather than run start. When a status poll finds the
transient absent:

- **Lock within TTL (`lock_age ≤ STATUS_TTL = 600s`):** the background process is still alive
  but has not broadcast yet (or the transient was evicted). `handle_missing_transient()` returns
  `status: running` (R3) — button stays in-progress, no state change.
- **Lock exceeded TTL (`lock_age > STATUS_TTL`):** background process died without releasing the
  lock. `abort_orphaned_run()` marks the `runs` row `failed`, releases the generation lock, deletes
  the status transient and queue option, and returns `status: error` with "infrastructure timeout"
  (R2b). A daily `Run_Reaper::sweep_stuck_runs()` at 2× `EXPECTED_RUN_SECONDS` (~3600s) remains
  the backstop for runs the poll path never sees.

---

## Database Schema

### Custom Tables

All tables use `$wpdb->prefix` + `prautoblogger_` prefix.

#### `ab_source_data` — Raw social media data

| Column        | Type              | Description                                      |
|---------------|-------------------|--------------------------------------------------|
| id            | BIGINT UNSIGNED   | Auto-increment PK                                |
| source_type   | VARCHAR(50)       | 'reddit' (extensible for future providers)        |
| source_id     | VARCHAR(255)      | Platform-specific unique ID (post/comment ID)     |
| subreddit     | VARCHAR(255)      | Subreddit or channel name (nullable)              |
| title         | TEXT              | Post/video title                                 |
| content       | LONGTEXT          | Post body / comment text / transcript             |
| author        | VARCHAR(255)      | Original author username                          |
| score         | INT               | Upvotes/likes/engagement metric                   |
| comment_count | INT               | Number of comments/replies                        |
| permalink     | VARCHAR(500)      | URL to original content                           |
| collected_at  | DATETIME          | When we collected this data                       |
| metadata_json | LONGTEXT          | Platform-specific extra data (JSON)               |

- INDEX: `source_type`, `collected_at`
- UNIQUE INDEX: `source_type`, `source_id`

#### `ab_analysis_results` — Analyzed topics and patterns

| Column           | Type              | Description                                    |
|------------------|-------------------|------------------------------------------------|
| id               | BIGINT UNSIGNED   | Auto-increment PK                              |
| analysis_type    | VARCHAR(50)       | 'question', 'complaint', 'comparison'          |
| topic            | VARCHAR(500)      | Detected topic/theme                           |
| summary          | TEXT              | LLM-generated summary of the pattern           |
| frequency        | INT               | How many source posts mention this             |
| relevance_score  | FLOAT             | LLM-assigned relevance score (0-1)             |
| source_ids_json  | LONGTEXT          | JSON array of ab_source_data.id references     |
| analyzed_at      | DATETIME          | When analysis was performed                    |
| metadata_json    | LONGTEXT          | Extra analysis data (JSON)                     |

- INDEX: `analysis_type`, `relevance_score`
- INDEX: `analyzed_at`

#### `prab_generation_log` — API cost and generation tracking

| Column            | Type              | Description                                   |
|-------------------|-------------------|-----------------------------------------------|
| id                | BIGINT UNSIGNED   | Auto-increment PK                             |
| post_id           | BIGINT UNSIGNED   | WordPress post ID (nullable, set after publish)|
| run_id            | VARCHAR(36)       | UUID linking all entries from one pipeline run |
| stage             | VARCHAR(50)       | See "Stage vocabulary" below. Canonical edit-stage name is **'polish'** (this doc previously said 'edit'; the code always wrote 'polish') |
| provider          | VARCHAR(50)       | 'openrouter'                                  |
| model             | VARCHAR(100)      | Model identifier used                         |
| prompt_tokens     | INT               | Input tokens consumed                         |
| completion_tokens | INT               | Output tokens generated                       |
| estimated_cost    | DECIMAL(10,6)     | Estimated USD cost                            |
| request_json      | LONGTEXT          | Full request payload (for debugging)          |
| response_status   | VARCHAR(20)       | 'success', 'error', 'timeout'                 |
| error_message     | TEXT              | Error details if failed (nullable)             |
| agent_role        | VARCHAR(50)       | v0.18.0, nullable. Role that made the call ('writer', 'editor', 'analyst', 'researcher', 'illustrator', …). Historical rows stay NULL |
| prompt_version    | VARCHAR(20)       | v0.18.0, nullable. Pinned prompt-registry version the call rendered with. Historical rows stay NULL |
| created_at        | DATETIME          | When the API call was made                    |

- INDEX: `post_id`
- INDEX: `run_id`
- INDEX: `created_at`
- INDEX: `stage`

**Stage vocabulary.** `stage` is a VARCHAR, not a SQL enum; the vocabulary lives in
`PRAutoBlogger_Stage_Display_Map` (PHP), which renders historical and new values coherently
and maps each stage to its default agent role + primary prompt-registry key:

- **Historical values (exist on prod):** `analysis`, `outline`, `draft`, `polish`, `review`,
  `llm_research`, `image_a`, `image_b`, `image_prompt_rewrite`, `opik_eval_judge`.
  Note: single-pass generation logs its one call as `draft` (no `outline` row — that absence
  is how single-pass and multi-step runs are told apart in cost reporting).
- **Pipeline v2 values (new runs may write):** `research`, `curate`, `draft`, `editorial`,
  `seo`, `publish`.
- Unknown values render via a humanizing fallback — the audit view never renders blank.

#### `prab_event_log` — Structured application logging

| Column     | Type              | Description                                     |
|------------|-------------------|-------------------------------------------------|
| id         | BIGINT UNSIGNED   | Auto-increment PK                               |
| level      | VARCHAR(10)       | 'error', 'warning', 'info', 'debug'             |
| context    | VARCHAR(100)      | Origin: 'pipeline', 'reddit', 'ga4', etc.       |
| message    | TEXT              | Human-readable log message                      |
| meta_json  | LONGTEXT          | Optional structured data (nullable)              |
| created_at | DATETIME          | When the event occurred                          |

- INDEX: `level`, `created_at`
- INDEX: `created_at`

#### `ab_content_scores` — Post performance metrics

| Column             | Type              | Description                                  |
|--------------------|-------------------|----------------------------------------------|
| id                 | BIGINT UNSIGNED   | Auto-increment PK                            |
| post_id            | BIGINT UNSIGNED   | WordPress post ID                            |
| pageviews          | INT               | Total pageviews (WP native or GA4)           |
| avg_time_on_page   | FLOAT             | Average seconds on page (GA4)                |
| bounce_rate        | FLOAT             | Bounce rate percentage (GA4)                 |
| comment_count      | INT               | WordPress comment count                      |
| composite_score    | FLOAT             | LLM-computed content quality score (0-100)   |
| score_factors_json | LONGTEXT          | JSON breakdown of what contributed to score  |
| measured_at        | DATETIME          | When metrics were collected                  |

- INDEX: `post_id`
- INDEX: `measured_at`
- INDEX: `composite_score`

#### Pipeline v2 substrate tables (v0.18.0, db 1.2.0)

Real table names use the standard prefix, e.g. `wp_prautoblogger_prompts` (the plan-of-record's
`prab_*` names are shorthand). Created by `PRAutoBlogger_Pipeline_Schema_Installer`; all are
dropped on uninstall.

##### `prautoblogger_prompts` — versioned prompt registry

| Column      | Type            | Description                                              |
|-------------|-----------------|----------------------------------------------------------|
| id          | BIGINT UNSIGNED | Auto-increment PK                                        |
| prompt_key  | VARCHAR(64)     | Registry key, e.g. 'content.single_pass' (`key` is reserved SQL) |
| version     | INT UNSIGNED    | Monotonic per key; **rows are immutable** — edits create new versions |
| body        | LONGTEXT        | Prompt template; `{{ token }}` placeholders filled at render time |
| model       | VARCHAR(100)    | Optional model hint (informational in Phase 1)           |
| params_json | LONGTEXT        | Optional params snapshot (temperature, max_tokens, …)     |
| author      | VARCHAR(100)    | Who created the version ('seed:v0.18.0', wp user login, agent) |
| created_at  | DATETIME        | Version creation time                                    |
| active      | TINYINT(1)      | Exactly one active row per key                           |

- UNIQUE: `prompt_key, version` · INDEX: `prompt_key, active`

##### `prautoblogger_run_sources` — per-run source audit

| Column        | Type         | Description                                  |
|---------------|--------------|----------------------------------------------|
| id            | BIGINT UNSIGNED | Auto-increment PK                         |
| run_id        | VARCHAR(36)  | Pipeline run UUID                            |
| agent_role    | VARCHAR(50)  | Role that surfaced the source               |
| source_url    | VARCHAR(500) | Source URL (nullable)                        |
| doi           | VARCHAR(255) | DOI when known (nullable)                    |
| kept          | TINYINT(1)   | Keep/discard decision                        |
| reason        | TEXT         | Why kept or discarded (nullable)             |
| quality_score | FLOAT        | Source quality weighting (nullable)          |
| created_at    | DATETIME     | Row creation time                            |

- INDEX: `run_id` — consolidates the old `source_ids_json` + `_prautoblogger_research_sources`
  scatter for **new** runs; historical runs are not backfilled.

##### `prautoblogger_run_decisions` — per-stage decision audit

| Column         | Type        | Description                                   |
|----------------|-------------|-----------------------------------------------|
| id             | BIGINT UNSIGNED | Auto-increment PK                         |
| run_id         | VARCHAR(36) | Pipeline run UUID                             |
| stage          | VARCHAR(50) | Stage that decided (see stage vocabulary)     |
| verdict        | VARCHAR(50) | e.g. 'approved', 'revised', 'rejected', 'halted' |
| rationale      | TEXT        | Decision rationale (nullable)                 |
| citation_score | FLOAT       | Nullable until the Phase-2 editorial loop computes it |
| human_modified | TINYINT(1)  | v0.20.0 — decision derives from a human-edited input (set on replay of the stage, or on decisions recorded while rebuilding a human-modified item) |
| created_at     | DATETIME    | Row creation time                             |

- INDEX: `run_id`

##### `prautoblogger_runs` — run ledger + lifecycle

| Column              | Type          | Description                                 |
|---------------------|---------------|---------------------------------------------|
| run_id              | VARCHAR(36)   | PK — pipeline run UUID                      |
| status              | VARCHAR(20)   | pending / running / done / failed / halted  |
| ceiling_usd         | DECIMAL(10,6) | Per-run cost ceiling snapshotted at run start |
| reserved_usd        | DECIMAL(10,6) | Outstanding reserve-before-call holds       |
| settled_usd         | DECIMAL(10,6) | Actual settled spend                        |
| overage_usd         | DECIMAL(10,6) | Amount the breaching reservation exceeded the ceiling by |
| pinned_prompts_json | LONGTEXT      | Map of prompt_key → version frozen at run start |
| started_at          | DATETIME      | Run start                                   |
| updated_at          | DATETIME      | Last ledger/state write                     |
| finished_at         | DATETIME      | Set on done/failed/halted (nullable)        |

- INDEX: `status` — the Cost_Governor's reserve is a single conditional UPDATE against this
  row (same atomic discipline as the #10 generation lock), so concurrent `curl_multi`
  writers cannot slip past the ceiling between check and call.

##### `prautoblogger_run_stages` — per-run per-stage state machine

| Column      | Type            | Description                                        |
|-------------|-----------------|----------------------------------------------------|
| id          | BIGINT UNSIGNED | Auto-increment PK                                  |
| run_id      | VARCHAR(36)     | Pipeline run UUID                                  |
| stage       | VARCHAR(50)     | Stage name (see stage vocabulary)                  |
| agent_role  | VARCHAR(50)     | Fan-out dimension. Populated in Phase 1 from `Stage_Display_Map::default_agent_role()` (e.g. 'writer', 'editor', 'publisher'). Phase-2 quorum passes multiple roles for the same stage. |
| item_key    | VARCHAR(64)     | Scopes article-level stages within multi-article runs ('' for run-level) |
| status      | VARCHAR(20)     | pending / running / done / failed / halted         |
| attempt     | SMALLINT UNSIGNED | Re-entry counter                                 |
| cost_usd    | DECIMAL(10,6)   | Cost attributed to the stage                       |
| meta_json   | LONGTEXT        | Stage output snapshot — lets resume reuse a done stage instead of re-charging it (payload pruned on the retention cron) |
| human_modified | TINYINT(1)   | v0.20.0 — set (sticky, never cleared) when an edited input fork enters execution for this row (CPO guardrail 2) |
| stale       | TINYINT(1)      | v0.20.0 — upstream changed after this row completed; row STAYS 'done' so resume never silently re-runs it; cleared only by a fresh done() |
| started_at  | DATETIME        | First entry                                        |
| updated_at  | DATETIME        | Last transition                                    |
| finished_at | DATETIME        | Set on done/failed (nullable)                      |

- UNIQUE: `run_id, stage, agent_role, item_key` (the idempotency key) · INDEX: `status, updated_at`

##### `prautoblogger_stage_inputs` — immutable input-version store (v0.20.0, db 1.3.0)

| Column       | Type            | Description                                      |
|--------------|-----------------|--------------------------------------------------|
| id           | BIGINT UNSIGNED | Auto-increment PK                                |
| run_id       | VARCHAR(36)     | Pipeline run UUID                                |
| stage        | VARCHAR(50)     | Stage name ('' for idea seeds)                   |
| agent_role   | VARCHAR(50)     | Stage row agent role                             |
| item_key     | VARCHAR(64)     | Article scope                                    |
| version      | INT UNSIGNED    | Monotonic per scope; **rows are INSERT-only** — edits create the next version, originals are never overwritten |
| source       | VARCHAR(20)     | 'human' (edit fork) or 'seed' (Article_Idea payload persisted at worker start) |
| request_json | LONGTEXT        | Fork body / idea JSON. Human fork bodies pruned by the retention cron after R days; seed rows (~1KB) kept |
| author       | VARCHAR(100)    | WP user login, or 'pipeline' for seeds           |
| created_at   | DATETIME        | Row creation time                                |

- UNIQUE: `run_id, stage, agent_role, item_key, version` · INDEX: `run_id`
- The ORIGINAL executed input of a stage is `generation_log.request_json` (B1) — it is
  never copied or modified here; forks are the edit history (CPO guardrail 1).

**db version note:** `PRAUTOBLOGGER_DB_VERSION` moved 1.1.0 → 1.3.0 in v0.20.0. The
constant never actually carried the "db 1.2.0" this document used as shorthand for the
v0.18.0 substrate; 1.3.0 leapfrogs the phantom value so the version-compare self-healing
migration fires exactly once everywhere.

### WordPress Options (`wp_options`)

All prefixed with `prautoblogger_`:

| Option Key                             | Description                                           |
|----------------------------------------|-------------------------------------------------------|
| `prautoblogger_openrouter_api_key`     | Encrypted OpenRouter API key                          |
| `prautoblogger_ai_gateway_base_url`    | Optional Cloudflare AI Gateway URL (proxies OpenRouter); empty = direct |
| `prautoblogger_ai_gateway_cache_ttl`   | Seconds Cloudflare may cache identical LLM responses (0 = off)           |
| `prautoblogger_ga4_property_id`        | Google Analytics 4 property ID                        |
| `prautoblogger_ga4_credentials_json`   | Encrypted GA4 service account credentials             |
| `prautoblogger_analysis_model`         | OpenRouter model for analysis (default: cheap)        |
| `prautoblogger_writing_model`          | OpenRouter model for writing (default: quality)       |
| `prautoblogger_editor_model`           | OpenRouter model for chief editor (default: quality)  |
| `prautoblogger_reasoning_max_tokens`   | v0.18.1 — hard cap on thinking tokens per call when reasoning is enabled; sent as OpenRouter `reasoning.max_tokens` (replaces `effort` when active) and the request `max_tokens` is raised by the cap so reasoning can never consume the visible-content budget; 0 = legacy uncapped effort mode. Default `PRAUTOBLOGGER_DEFAULT_REASONING_MAX_TOKENS` (2048) |
| `prautoblogger_daily_article_target`   | Number of articles per day (1-10, default: 1)         |
| `prautoblogger_writing_pipeline`       | 'single_pass' or 'multi_step' (default: multi_step)  |
| `prautoblogger_niche_description`      | Text description of the site's niche                  |
| `prautoblogger_target_subreddits`      | JSON array of subreddits to monitor                   |
| `prautoblogger_monthly_budget_usd`     | Monthly API spend limit in USD                        |
| `prautoblogger_per_run_cost_ceiling_usd` | v0.18.0 — per-run hard cost ceiling (USD); reserve-before-call enforced; snapshotted onto the runs row at run start; 0 = disabled. Default `PRAUTOBLOGGER_DEFAULT_RUN_CEILING_USD` ($0.50) |
| `prautoblogger_request_json_retention_days` | v0.18.0 — days before heavy audit payloads (generation_log.request_json, run_stages.meta_json) are NULLed by the daily cleanup cron; 0 = keep forever. Default `PRAUTOBLOGGER_DEFAULT_REQUEST_JSON_RETENTION_DAYS` (14) |
| `prautoblogger_tone`                   | Content tone (informational, conversational, etc.)    |
| `prautoblogger_min_word_count`         | Minimum article word count (default: 800)             |
| `prautoblogger_max_word_count`         | Maximum article word count (default: 2000)            |
| `prautoblogger_topic_exclusions`       | JSON array of topics to never write about             |
| `prautoblogger_enabled_sources`        | JSON array of active source types                     |
| `prautoblogger_auto_publish`           | Toggle: auto-publish approved posts (default: '0')    |
| `prautoblogger_default_author`         | WP user ID for generated post authorship              |
| `prautoblogger_default_category`       | WP category ID fallback for generated posts           |
| `prautoblogger_log_level`              | Logging threshold: error/warning/info/debug           |
| `prautoblogger_db_version`             | Schema version for migrations                         |
| `prautoblogger_schedule_time`          | Daily generation time (HH:MM, default: '03:00')       |
| `prautoblogger_image_model`            | Image model slug from `Image_Model_Registry::get_models()`; provider is derived from this on save |
| `prautoblogger_image_provider`         | Derived from the chosen model on save (v0.8.0+); not editable in admin UI |
| `prautoblogger_image_prompt_instructions` | System prompt given to the image rewriter LLM (v0.8.0+); falls back to `Image_Prompt_Builder::REWRITER_SYSTEM_PROMPT` when empty. v0.16.0: the default now instructs the LLM to emit a 1-2 sentence editorial topic/mechanism summary as the SCENE (was a comic gag) |
| `prautoblogger_image_nsfw_retry`       | Toggle: retry NSFW-blocked image slots with a rule-based fallback prompt (default: '1') |
| `prautoblogger_migrated_image_provider_v080` | One-shot migration flag — auto-heals mismatched provider/model pairs on first admin_init after v0.8.0 upgrade |
| `prautoblogger_article_font_family`    | Font family key: 'default', 'inter', 'georgia', 'merriweather', 'lora', 'open_sans', 'roboto', 'system' |
| `prautoblogger_article_font_size`     | Body font size in px (0 = theme default). Recommended: 16–18. |
| `prautoblogger_table_borders`         | Toggle: add borders/padding/striping to tables (default: '1') |
| `prautoblogger_image_style_template`   | Full image prompt template (v0.16.0+); contains exactly one `{{ topic_summary }}` token filled per-article with the rewriter scene. Default = `PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE` (editorial scientific illustration). Replaces the Style Suffix. Validated on save (single-token, brief A5) |
| `prautoblogger_image_style_suffix`     | DEPRECATED (v0.16.0). Former comic style suffix appended to every image prompt; no longer read by the builder. Mirrored to `prautoblogger_image_style_suffix_deprecated` for one cycle and not deleted yet |
| `prautoblogger_openrouter_model_registry` | Normalized OpenRouter model list (JSON array, daily refresh, serves as durable cache) |
| `prautoblogger_openrouter_model_registry_fetched_at` | Unix timestamp of last successful model registry refresh |
| `prautoblogger_image_compose_enabled`  | Toggle: run the deterministic image composer (default '1'; auto-degrades, never blocks publishing) |
| `prautoblogger_image_compose_variants` | Comma list of composed variants for Image A (default 'og,square'; whitelisted at point of use) |
| `prautoblogger_image_featured_mark_enabled` | Toggle: subtle corner mark on featured images (default '1') |
| `prautoblogger_image_compose_capability` | Cached capability probe `{fingerprint, capability}`; fingerprint = PHP version + imagick/gd presence, so it auto-invalidates on host changes |
| `prautoblogger_board_poll_interval`     | Mission Brief board poll interval in seconds (default 5, min 3). Localized into board.js. |
| `prautoblogger_board_published_window_days` | Days back to show in the Published column (default 7). |

### Post Meta

Stored on every PRAutoBlogger-generated post:

| Meta Key                              | Description                                   |
|---------------------------------------|-----------------------------------------------|
| `_prautoblogger_generated`            | Boolean flag — '1' if generated by plugin     |
| `_prautoblogger_analysis_id`          | FK to ab_analysis_results.id                  |
| `_prautoblogger_source_ids`           | JSON array of source data IDs used            |
| `_prautoblogger_model_used`           | Model that generated the content              |
| `_prautoblogger_pipeline_mode`        | 'single_pass' or 'multi_step'                 |
| `_prautoblogger_total_cost`           | Total USD cost for this post                  |
| `_prautoblogger_total_tokens`         | Total tokens consumed for this post           |
| `_prautoblogger_editor_verdict`       | 'approved', 'revised', 'rejected'             |
| `_prautoblogger_editor_notes`         | Chief editor's review notes                   |
| `_prautoblogger_generated_at`         | ISO 8601 timestamp of generation              |
| `_prautoblogger_research_sources`     | JSON array of source URLs used                |
| `_prautoblogger_idea_hash`            | v0.18.0 — stable hash of the idea (title|topic); with `_prautoblogger_run_id` forms the post-creation idempotency key |
| `_prautoblogger_og_image_id`          | Attachment ID of the composed 1200×630 OG variant (v0.17.0; the rebuilt SEO stage emits `og:image` from this — key name frozen) |
| `_prautoblogger_square_image_id`      | Attachment ID of the composed 1080×1080 square variant (v0.17.0; stored now, no consumer yet) |

Composed-variant **attachment** meta (v0.17.0): every pipeline attachment gets
`_prautoblogger_image_role` (`featured`/`og`/`square`); OG/square variants also
get `_prautoblogger_base_attachment_id` pointing at the featured attachment
they derive from. All keys keep the `_prautoblogger_` prefix, so the uninstall
prefix-sweep purges them.

---

## External API Integrations

| Service | Purpose | Auth | Rate Limit | Code |
|---------|---------|------|------------|------|
| OpenRouter | All LLM calls (analysis, writing, editing) | API key (encrypted in wp_options) | Per-model | `providers/class-open-router-provider.php`, `providers/class-open-router-pricing.php` |
| Cloudflare AI Gateway (optional) | Transparent proxy in front of OpenRouter — adds response caching, cost logging, rate limiting, provider fallback | Same OpenRouter key; gateway URL in `prautoblogger_ai_gateway_base_url` | Gateway-side quotas | Same provider file; activated when gateway URL option is non-empty |
| Reddit RSS | Primary Reddit data source — Atom feeds for subreddit hot posts | None (unauthenticated) | No known rate limit; reliable from datacenter IPs | `providers/class-reddit-json-client.php` |
| Reddit .json | Fallback for posts + only source for comments | None (unauthenticated) | ~10 req/min (datacenter IPs often blocked) | `providers/class-reddit-json-client.php` |
| Google Analytics 4 | Post performance metrics | OAuth2 service account | Standard GA4 limits | `core/class-ga4-client.php`, `core/class-metrics-collector.php` |
| Runware (FLUX.1 via runware.ai) | **Default** image backend (v0.9.0+): schnell ~$0.0006/image, dev ~$0.02/image. True parallel generation via curl_multi. **v0.15.0: live model catalog sync** from `/v1/models` endpoint (task-based API); normalized to Image_Model_Registry shape; cached with 24h TTL; on-demand refresh via AJAX. | API key (encrypted in wp_options) | Account-level quotas | `providers/class-runware-image-provider.php`, `providers/class-runware-image-pricing.php`, `providers/class-runware-image-support.php`, `providers/class-runware-image-batch.php`, `providers/class-runware-model-catalog.php` |

---

## Opik LLM Observability (Optional)

**Status:** Wave 1 (v0.12.0) — tracing skeleton. Wave 2 (future) — eval harness with frozen-dataset regression testing.

Opik is a cloud-hosted LLM observability platform (Comet) providing:
- **Per-call tracing:** Every OpenRouter call generates a span with model, tokens, latency metadata.
- **Trace grouping:** One trace per article generation bundles all spans (draft, QA, image prompt).
- **Prompt versioning:** Foundation for Wave 2 — compare new prompt revisions against a frozen reference dataset using LLM-as-judge scoring on rubric axes (factual accuracy, style match, hallucination risk).

**Architecture:**
- **Feature flag:** `prautoblogger_opik_enabled` (WP option, default off). When false, zero network traffic to Opik.
- **Credentials:** API key and workspace from PHP constants `PRAUTOBLOGGER_OPIK_API_KEY`, `PRAUTOBLOGGER_OPIK_WORKSPACE` (defined in wp-config.php). Never stored in DB.
- **Span lifecycle:** 
  1. `Opik_Trace_Context::init_trace()` generates UUID at article-worker start.
  2. Each LLM call wraps with `start_span()` → LLM request → `end_span()` with tokens + response.
  3. `finalize_trace()` + `Opik_Span_Queue::enqueue()` accumulates trace + spans for async dispatch.
  4. `prautoblogger_opik_dispatch` cron action batches spans/traces (max 100 per POST) to `/v1/private/spans/batch`, `/v1/private/traces/batch`.
- **Retry logic:** Exponential backoff (2s, 4s, 8s) on 5xx errors; permanent abort on 4xx; dropped after 3 attempts per item.
- **Queue:** WP options-backed; max 1000 items; 12-hour TTL; persists across page reloads for truly async dispatch.

**Call sites instrumented (Wave 1):**
- Draft generation (single-pass or multi-step outline/draft/polish).
- Editorial review (chief editor LLM call).
- Image prompt generation (deferred; file at 314 LOC, over limit).

**Cost & Performance:**
- Opik free tier: 25k spans/month. Estimated current usage ~4.2k/month (20 posts/day × 7 calls × 30 days). Headroom to 5× growth.
- Impact on article generation: async dispatch, zero blocking latency overhead.
- Wave 2 (eval harness): adds LLM-as-judge calls (~$14/month at Haiku pricing, assuming 2 eval runs/week).

**Code structure:**
```
includes/services/opik/
  class-opik-client.php            — REST client (auth, retry, batch POSTs)
  class-opik-trace-context.php     — per-request trace state singleton
  class-opik-span-queue.php        — WP options-backed async queue
  class-opik-dispatcher.php        — cron handler, batch dispatch

includes/admin/
  class-opik-settings.php          — admin UI (toggle, project name, status)
```

---

## Key Decisions

### #1: OpenRouter over direct provider APIs
Gives access to many models through one API, simplifies provider management, and lets the user switch models without code changes. Trade-off: slight latency overhead and dependency on OpenRouter's availability.

### #2: Reddit as first social source (RSS primary, .json fallback)
Reddit has rich discussion data ideal for identifying recurring questions and pain points. Reddit RSS/Atom feeds are the primary data source — they work reliably from datacenter IPs (Hostinger) where .json endpoints return 403. The .json endpoints serve as a fallback for posts and are the only source for comment fetching (RSS doesn't include comments). Trade-off: RSS lacks engagement metrics (score, comment count), but post titles and content are sufficient for topic analysis.

### #3: Chief editor agent instead of human review queue
The user wants full automation — a second LLM pass reviews quality, SEO, and accuracy before publishing. Posts that fail editorial review are flagged for human intervention rather than published. Trade-off: doubles the LLM cost of the "review" step, but catches quality issues.

### #4: Configurable pipeline (single-pass vs. multi-step)
Some users want cheap/fast, others want high quality. Making this configurable avoids forcing one approach. Trade-off: more code paths to maintain.

### #5: Composite content score using LLM
Rather than just tracking raw metrics, we periodically have the LLM evaluate what made high-performing posts succeed and low-performing posts fail. This feeds back into the analysis and generation prompts. Trade-off: additional API cost for scoring, but enables the self-improvement loop.

### #6: Source provider interface for future platforms
Reddit is the only implemented source, but the interface pattern means adding a new source (YouTube, TikTok, etc.) is one class implementation plus a settings checkbox. We removed the old stub provider files (TikTok, Instagram, YouTube) because dead code confuses AI agents and humans alike — the interface is the contract, not empty classes. Trade-off: slight over-engineering for day one, but pays off immediately at source #2.

### #7: Custom tables for high-volume data
Source data, analysis results, and generation logs are high-write, time-series data. WordPress post_meta and options are wrong for this. Custom tables with proper indexes. Trade-off: more complex activation/uninstall, but correct.

### #8: All API keys encrypted at rest
Using `wp_salt()` as encryption key with OpenSSL. Not bulletproof (salt is in wp-config.php) but significantly better than plaintext in wp_options. Trade-off: adds complexity to option get/set, but necessary for security.

### #9: Structured logger instead of raw error_log()
All application logging flows through `PRAutoBlogger_Logger` singleton, which writes to the `prab_event_log` table and forwards errors/warnings to PHP's `error_log()`. This gives users an in-admin Activity Log page with filtering by level, search, and pagination — much more accessible than server logs. Trade-off: one extra DB write per log entry, but the table is prunable and indexed.

### #10: Database-level atomic mutex for generation lock
Uses `INSERT IGNORE` on wp_options (which has a UNIQUE index on `option_name`) instead of transient-based locking. This eliminates the TOCTOU race condition where two concurrent cron runs could both read the transient as "not locked" before either sets it. Expired locks older than 1 hour are cleaned up first to prevent permanent deadlock.

### #11: wp.element for frontend React (no build step)
The posts widget uses WordPress-bundled React (`wp.element`) with raw `createElement` calls instead of JSX. This avoids requiring a Node.js build pipeline for what is a simple card grid. Trade-off: more verbose component code, but zero tooling dependencies for the plugin consumer.

### #12: REST API for frontend data fetching
The widget fetches posts asynchronously from a dedicated REST endpoint rather than rendering server-side. This keeps initial page load fast and allows the endpoint to be cached independently (5-minute Cache-Control). The permission callback uses a filter (`prautoblogger_rest_posts_public`) so sites can restrict access if needed.

### #13: Run-ID based log linking
Each pipeline execution generates a UUID (`run_id`) that tags every `prab_generation_log` entry. When a post is published, `link_generation_logs()` uses `UPDATE WHERE run_id = X` to associate entries with the `post_id`. This is more reliable than the previous timestamp-window approach, especially in batch runs where multiple posts are generated in quick succession. **v0.8.1+**: `run_id` is also persisted on each post as `_prautoblogger_run_id` post_meta, giving the orphan-research reaper a direct path from run to siblings without walking the gen_log table (see #19).

### #19: Daily orphan-research-row reaper (v0.8.1)
When a pipeline dies between post creation and the final `Post_Assembler::amortize_research_costs()` call (Hostinger exec kill, OOM, LiteSpeed cut, fatal), the shared `llm_research` cost row stays in `prab_generation_log` with `post_id = NULL` and sibling articles understate their true cost in the breakdown popover. A new daily WP-Cron event `prautoblogger_reap_orphan_research_rows` (hooked to `PRAutoBlogger_Research_Reaper::on_cron`, 03:15 server time — 15 min after the primary generation cron) scans for orphan rows older than a 1-hour grace window, looks up sibling posts by `_prautoblogger_run_id` post_meta (fallback: distinct `post_id`s in gen_log for the same run_id), and delegates to the existing idempotent `amortize_research_costs()` on match. Orphans that still have no siblings after 7 days are deleted outright as sunk cost. A one-time activator migration (`prautoblogger_migrated_run_id_meta_v081`) back-populates `_prautoblogger_run_id` on existing posts from gen_log so the meta-primary path works on upgrade. Manual ops trigger: `wp prautoblogger reap-research`.

### #14: Reddit RSS replaces PullPush.io (and earlier Reddit OAuth)
Reddit rejected our OAuth API application (April 2026). We initially switched to PullPush.io, but its index was frequently stale or unavailable. Reddit's RSS/Atom feeds (`/r/{sub}/hot.rss`) proved the most reliable option — they work from datacenter IPs where .json gets 403, require no auth, and have no apparent rate limit. The .json endpoints are kept as a fallback for posts and as the sole source for comment data. Each collected item's metadata includes a `data_source` field (`reddit_rss` or `reddit_json`) for auditability.

### #18: OpenRouter model registry — daily refresh, WP option + transient cache, zero-coupling

The admin model picker (v1) needs to list OpenRouter models with pricing and capabilities. We fetch `https://openrouter.ai/api/v1/models` (free, unauthenticated) once daily and store the normalized payload in a WP option fronted by a 24h transient. On stale-and-fetch-fails, we serve last-good + surface a warning. The registry class (`class-open-router-model-registry.php`) takes all config via constructor (option name, transient name, endpoint URL) — no PRAUTOBLOGGER_* constants inside the class body. Phase 2 lifts it into a shared Composer package with zero internal edits. Phase 3 adds a parallel `Cloudflare_WorkersAI_Model_Registry` behind the same interface. Capability vocabulary: `text→text`, `text+image→text`, `text+audio→text`, `text→image`, `text→audio`, `text→video`, `text→embedding`. Cost: $0/month.

### #20: Editorial illustration prompt + style-template setting (v0.16.0, May 2026)

Commit 1 of the in-plugin editorial image pipeline (brief `docs/proposals/2026-05-29-image-pipeline-in-plugin-brief.md`). The image style pivots from a single-panel newspaper comic to a **text-free editorial scientific illustration**. Two changes ship here; the deterministic PHP composer is a later commit.

1. **Prompt structure.** `Image_Prompt_Builder::REWRITER_SYSTEM_PROMPT` now asks the rewriter LLM for a concise 1-2 sentence topic/mechanism summary (one concrete centered focal subject, no text/people-as-gag/logos) as the SCENE, keeping the short CAPTION line for the unchanged HTML-caption-below-image path. The scene/caption parsing contract (`Image_Scene_Parser`) is unchanged.
2. **Template substitution.** The old `trim($scene . ' ' . $style_suffix)` concat is replaced by `PRAutoBlogger_Image_Template_Filler::fill($scene)` in all three entry points (`build_article_prompt`, `build_source_prompt`, `build_fallback_prompt`). The filler resolves the active template (admin override → default), strips control chars and clamps the summary length (brief A5), then substitutes the single `{{ topic_summary }}` token. Degradation (brief A5/A6): if the template lacks exactly one token it appends the summary and logs a warning; if the summary is empty it emits the style-only prompt — a blank or token-only prompt is never sent to the provider.
3. **Setting.** New textarea `prautoblogger_image_style_template` (default `PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE`) replaces the Style Suffix field. On save it is sanitised with `sanitize_textarea_field` and validated to contain exactly one token (`Image_Template_Filler::sanitize_for_save`). The old `prautoblogger_image_style_suffix` is mirrored to `..._deprecated` for one cycle and not deleted (one-time migration `prautoblogger_migrated_style_template_v0160`).

Provider/model are unchanged — only the prompt text changes, so production keeps emitting via Runware FLUX.1 schnell but now produces text-free editorial base images.

### #21: Deterministic PHP image composer with capability ladder (v0.17.0, Jun 2026)

Commit 2 (and final scope) of the in-plugin editorial image pipeline (#20). Small diffusion
models cannot render clean text (2026-04-21 A/B finding), so base images are text-free —
but the one image that travels *without* its HTML caption is the social/OG share image.
A **deterministic local composer** (`PRAutoBlogger_Image_Composer` behind
`PRAutoBlogger_Image_Composer_Interface`) now runs between provider bytes and sideload:

1. **Variants.** Image A → corner-marked featured (1200×632) + branded OG (1200×630, teal
   band, brand mark, baked caption in Poppins SemiBold) + square card (1080×1080: full base
   downscaled into a 1080×569 top slice — no upscaling — cream caption panel in Poppins
   Bold, teal footer with the horizontal reverse lockup). Image B → corner mark only. The
   variant set is config-driven (`prautoblogger_image_compose_variants`, default `og,square`;
   square has no consumer yet — CEO wants it rendered + stored now). Variants are
   meta-linked via `_prautoblogger_image_role`, `_prautoblogger_base_attachment_id`,
   `_prautoblogger_og_image_id`, `_prautoblogger_square_image_id`. The og key is **frozen**:
   the rebuilt SEO stage (pipeline-redesign §4.8) emits `og:image` from it.
2. **Capability ladder, never fatal.** Imagick is probed once (PNG format + TTF annotate
   smoke test) and cached in `prautoblogger_image_compose_capability`, fingerprinted by
   PHP version + imagick/gd presence so host flips (hPanel/cagefs — it has happened)
   auto-invalidate the cache. Rungs: imagick → `wp_get_image_editor()` resize-only
   (unbranded, square skipped because it would upscale) → pass-through. Every render is
   try/caught; degradation logs one WARNING per run; the publish always proceeds with the
   editable HTML caption intact. Test/ops override: `prautoblogger_image_compose_capability`
   filter.
3. **Determinism.** Bundled OFL fonts (no system-font queries), vendored brand PNGs (no
   runtime SVG delegate variance), `stripImage()` + PNG date/time chunks excluded + fixed
   compression → byte-stable output **per environment** (not across ImageMagick versions).
   Composition is local CPU, $0, logged as a duration-only stage on the `image_composer`
   channel — no cost-tracker rows.
4. **Layout tuning.** Geometry/opacity are constants in
   `PRAutoBlogger_Image_Composer_Layout::defaults()` behind the
   `prautoblogger_image_compose_layout` filter — deliberately not settings; promote only if
   the CEO asks to tune them. The caption is hard-clamped (word-boundary wrap + ellipsis,
   multibyte-safe) even though the rewriter LLM is already constrained to <15 words.

### #17: Runware as default image backend (v0.9.0, Apr 2026)

After a comic-style A/B round in Apr 2026, FLUX.1 schnell via Runware was chosen as the default image backend. Cost is ~$0.0006/image vs ~$0.039/image for Gemini 2.5 Flash Image via OpenRouter (≈65× cheaper), and the looser schnell fidelity reads as editorial-cartoon style — a feature, not a bug, for our single-panel comic aesthetic. FLUX.1 dev (~$0.02/image, 28 steps) remains available as an opt-in for higher-fidelity runs. The Runware layer mirrors the OpenRouter split (interface + provider + support + pricing + batch), all under the 300-line cap. The batch class dispatches `imageInference` POSTs via `curl_multi_init/exec/select` — wall-clock time for the A/B pair drops to ≈ the slowest single image. A v0.9.0 one-time migration (`prautoblogger_migrated_default_image_v090`) flips sites still on the legacy default; explicit user selections are preserved. Cloudflare Workers AI was removed as an image provider in v0.10.0 (see ADR #16 note below).

### #16: Image generation via Cloudflare Workers AI (FLUX.1) — SUPERSEDED v0.10.0
Kept here for history. Until v0.8.2 the default image backend was FLUX.1 schnell on Cloudflare Workers AI, routed via the Cloudflare AI Gateway for caching + unified telemetry. In v0.9.0 (2026-04-21) Runware FLUX.1 schnell became the default (65× cheaper, see ADR #17). In v0.10.0 (2026-04-21) the Cloudflare Workers AI provider was removed entirely — classes, settings, tests, and registry entries are gone; any CF user is migrated to `runware:100@1` on upgrade via `PRAutoBlogger_Migrate_Remove_Cloudflare_V0100::run()`. The Cloudflare AI Gateway in front of OpenRouter (ADR #15) is unaffected — it is a distinct integration.


### #15: Optional Cloudflare AI Gateway in front of OpenRouter
We already use Cloudflare for DNS/CDN on peptiderepo.com, so layering AI Gateway in front of OpenRouter is zero marginal infrastructure. It gives us response caching (meaningful for repeated classification/scoring calls), a unified cost/latency dashboard, rate limiting, and provider fallback — all of which we would otherwise have to build ourselves to satisfy the CTO cost-tracking rules. Kept as an opt-in URL setting (`prautoblogger_ai_gateway_base_url`) so the plugin still works unchanged out of the box and can be bypassed instantly if the gateway misbehaves. The gateway is a transparent OpenRouter-compatible proxy; no new provider class is needed, and the response parsing path (`usage`, `choices[0].message.content`) is unchanged.

### #25: Pipeline Settings page — per-step model, prompts, params + step options (v0.23.0 M1, v0.24.0 M2)

**M1 (v0.23.0 — additive):** A new "Pipeline" wp-admin submenu page surfacing per-step
model picker, system instructions, and agent prompts for every LLM stage. In M1 the
existing Settings sections (AI Models, Content, Sources) remained in place.

**M2 (v0.24.0 — decomposition):** Retired the AI Models, Content, and Sources Settings
tabs. All fields from those tabs now live exclusively in Pipeline Settings per-step panels.
The underlying wp_options are unchanged; only the editing UI has moved. The Settings page
now shows only: API Keys, Schedule & Budget, Publishing, Analytics, Display, Images.

**Retired sections:** `prautoblogger_models`, `prautoblogger_content`, `prautoblogger_sources`
removed from `PRAutoBlogger_Settings_Fields::get_sections()`. Their field definitions
removed from `get_core_fields()` and `PRAutoBlogger_Settings_Fields_Extended::get_fields()`.
The `uninstall.php` wildcard purge (`LIKE 'prautoblogger\_%'`) still covers all options.

**New M2 classes:**
- `PRAutoBlogger_Pipeline_Settings_Option_Fields` — public API: `contexts()`, `allowed_options()`, `get_fields_for_context()`, `sanitize_option()`.
- `PRAutoBlogger_Pipeline_Settings_Option_Fields_Data` — raw field arrays per context (split for 300-line rule).

**Save handler extension:** `pipeline_action = save_step_settings` with `step_context`
(global|research|analysis|writer|editorial). Validates context, iterates field defs,
sanitizes per type (textarea/select/number/toggle/checkboxes), persists via `update_option()`.

**Template additions:** Global Content Context block (editable niche_description form)
at top of Pipeline page; per-step `pipeline-settings-step-options.php` partial renders
type-aware field controls inside the step panel.

**Classes:** `PRAutoBlogger_Pipeline_Settings_Page` (registration + asset enqueue),
`PRAutoBlogger_Pipeline_Settings_Renderer` (view data assembly + template include),
`PRAutoBlogger_Pipeline_Settings_Save_Handler` (nonce + capability + sanitize + write),
`PRAutoBlogger_Pipeline_Settings_Step_Map` (canonical step definitions + key allowlists),
`PRAutoBlogger_Pipeline_Settings_Option_Fields` + `_Data` (step option allowlist + sanitizer).

**Security:** `manage_options` + nonce on every POST. Option names validated against
`PRAutoBlogger_Pipeline_Settings_Option_Fields::allowed_options()` (enforced per field id,
not via a POST-key allowlist, since the save handler iterates field defs directly).

**Prompt writes (M1, unchanged):** Immutable registry rows via
`PRAutoBlogger_Prompt_Registry_Writer::create_version()`; only allowlisted prompt keys.

**M3 (v0.25.0 — assembled-instructions preview + version history/diff):**
A per-prompt Template/Preview toggle replaces the static editor header. The editable
"Template" view remains unchanged; the "Preview assembled instructions" tab is read-only
and shows the actual rendered text the LLM received (sourced from the last successful
`generation_log.request_json` for the stage, extracted via `Stage_Display_Map`). Falls
back to a sample render with `[token_name]` placeholders when no run exists yet. A
collapsible version history accordion beneath each editor lists all stored versions
(number, author, timestamp, active badge) with "Diff" buttons that compute and render
an LCS-based inline diff (added/removed/context/omitted lines, 3-line context window).

**M3 new files:** `ajax/class-pipeline-preview-handler.php`,
`ajax/class-pipeline-history-handler.php`, `admin/class-pipeline-preview-source.php`.

**Security (M3):** All three new AJAX actions require `manage_options` + nonce. Prompt
keys validated against `Step_Map::allowed_prompt_keys()`. Preview returned as `esc_html`
server-side; diff text inserted via `textContent` (not innerHTML) in JS.

**M4 (v0.26.0 — Generation History: run list + per-step I/O drill-down):**
A new hidden admin page (`prautoblogger-gen-history`) lists all pipeline runs newest-first
(20 per page). Each row links to the Article Dossier (for runs with a linked post) and
exposes a "Stage I/O" toggle. The toggle opens an inline AJAX-loaded panel showing every
generation_log stage's full INPUT (system + user message content from `request_json`) and
OUTPUT (model response from `run_stages.meta_json.output`). Log-only stages (image_a, image_b,
llm_research, image_prompt_rewrite) have no run_stages row; their output is reported as null
(not pruned). Pruned outputs (meta_json present, output key absent) are flagged explicitly.
Stage labels come from `Stage_Display_Map::label()` — Phase 2b stages appear automatically.

**M4 new files:** `admin/class-gen-history-query.php`, `admin/class-gen-history-page.php`,
`ajax/class-gen-run-io-handler.php`, `templates/admin/gen-history-page.php`,
`assets/css/gen-history.css`, `assets/js/gen-history.js`.

**Security (M4):** `prautoblogger-gen-history` page requires `manage_options`. The
`prautoblogger_gen_run_io` AJAX action requires `manage_options` + nonce
(`prautoblogger_gen_run_io`). All output escaped via `esc_html()`/`esc_url()` server-side;
JS renders prompt/response text via `textContent` (never `innerHTML`). Read-only — no writes.

**M3 P2 sweep (v0.26.0):** Removed dead `data-preview-nonce` and `data-diff-nonce` HTML
attributes from `pipeline-settings-prompt-panel.php`. The JS reads nonces from
`prabPipeline.*Nonce` (via `wp_localize_script`), not from `data-*` attrs. Removed 3
redundant `wp_create_nonce()` calls from the renderer.


---

### #22: Pipeline v2 Phase 1 substrate (v0.18.0, db 1.2.0, June 2026)

Phase 1 of the CEO-approved pipeline rebuild (plan of record:
`convo/cpo/threads/2026-06-prautoblogger-pipeline-redesign/03-cto-revised-brief.md`), built
**on the current pipeline with no behavior change** to the Economy (single-pass) and
multi-step publish paths. Four subsystems:

1. **Versioned prompt registry** (`prautoblogger_prompts`). All pipeline prompt copy
   (content/analysis/editor/research + the illustration rewriter prompt and the image Style
   Template) lives as immutable versioned rows; the hardcoded v0.16.0 texts are seeded as v1
   and remain the in-code fallback, so a missing/empty table can never fatal — call sites
   render through `PRAutoBlogger_Prompt_Registry` and fall back to the same constants the
   seed used (byte-identical output). A run pins the active version of every key at start
   (`runs.pinned_prompts_json`) and every generation_log row is stamped with the pinned
   `prompt_version`. The image-composer PR **consumes** the `image.*` keys — it must not
   grow its own prompt storage. No admin UI in Phase 1; the API supports list/activate/
   create-version (never mutate) for the Phase-2 Prompts screen.
2. **Additive audit schema.** `agent_role` + `prompt_version` columns on generation_log;
   `run_sources` + `run_decisions` child tables; `PRAutoBlogger_Stage_Display_Map` renders
   historical + image + v2 stage values. `request_json` (and stage output payloads in
   `run_stages.meta_json`) are nulled after R days — R from the
   `prautoblogger_request_json_retention_days` setting (default 14), pruned on the existing
   daily reaper cron.
3. **Per-run cost governor** (`PRAutoBlogger_Cost_Governor`). Reserve-before-call against the
   per-run ledger row using a single conditional UPDATE (the #10 atomic-write discipline);
   estimates come from the #18 pricing chain; `curl_multi` image batches reserve their summed
   estimate before dispatch; reservations settle to actuals after each response. Breach →
   run `halted`, overage recorded, un-dispatched work aborted, surfaced on the Review Queue.
   Ceiling = `prautoblogger_per_run_cost_ceiling_usd` setting (default $0.50, 0 = disabled).
   The monthly `Cost_Tracker` cap and the Cloudflare AI Gateway (#15) path are unchanged.
4. **Run state machine + idempotency** (`PRAutoBlogger_Run_State`). Per-run per-stage state
   (pending/running/done/failed/halted) keyed `run_id + stage + agent_role + item_key`
   (item_key scopes article-level stages because one run_id spans all N articles of a batch);
   done stages persist output and are reused on re-entry, never re-charged; post creation is
   keyed by `_prautoblogger_run_id` + `_prautoblogger_idea_hash` (check-before-insert) so a
   retried run cannot duplicate a post. `PRAutoBlogger_Run_Reaper` (same daily cron as #19)
   marks runs stuck `running` > 2× expected stage wall-clock as `failed`; such runs render
   as **"incomplete"** in audit queries. Quorum logic (multiple roles per stage) itself is Phase 2. In Phase 1,
   `agent_role` is populated on every row from `Stage_Display_Map::default_agent_role()`
   so the run timeline is self-describing and the idempotency key is fully populated.

### #20: PHPUnit test infrastructure + WordPress-Core PHPCS compliance (v0.10.1)

Tests use Brain\Monkey for WordPress function mocking (no database required). The BaseTestCase singleton handles setup/teardown of Monkey stubs and provides fixture helpers for common data shapes (SourceData, ArticleIdea, GenerationLog, etc.). All WordPress functions used by the plugin are stubbed in BaseTestCase::setUp() so individual test classes only override what they need. In v0.10.1, missing stubs for `post_type_exists()` were added to support PeptideLinker's PR-Core guards. All 1,362 WordPress-Core coding-standards violations (short array syntax, line length, spacing) were auto-fixed via phpcbf and the CI gate was flipped to strict mode — PHPCS failures now block CI. This prevents future regressions on the code style front.



### #22b: Kanban board as primary admin landing screen (v0.19.0 / v0.19.1)

**Hidden admin page convention (v0.19.3):** See CONVENTIONS.md §Hidden Admin Pages for the full rule. Summary: hidden admin pages (link-accessed only, not visible in nav) MUST use `options.php` as the `add_submenu_page()` parent. NEVER unset/remove registered pages from `$submenu` after registration -- doing so causes `get_admin_page_parent()` to fail at request time, which makes WP recompute the hookname in the `admin_page_*` orphan namespace, find no registered handler, and call `wp_die(403)`. This is the dossier 403 incident (v0.19.2→v0.19.3). Same hookname-mismatch class as the board 404 (v0.19.0→v0.19.1); the only difference is that the board bug was a priority-ordering issue at registration time while the dossier bug was a post-registration $submenu mutation.

The primary admin screen is now a Mission Brief board (M5, v0.27.0) -- a vertical run-list
+ right-rail inspector layout (CEO-selected Direction C "Mission Brief"). A separate
`class-board-page.php` registers the `Board` submenu and a `$submenu` reorder makes it
the first (primary) entry. `class-board-data-provider.php` orchestrates the four status
sections (Generating | In review | Published | Failed) and enriches each card with a
lightweight `run_stages_summary` for the dot-rail. Raw `prab_generation_log` queries
(Generating, Failed) remain in `class-board-gen-log-query.php`. The right-rail inspector
is powered by `ajax/class-board-inspector-handler.php`, which reuses
`PRAutoBlogger_Gen_History_Query::get_run_io()` from M4 (no new DB queries/schema).
Poll interval and published window are settings-backed, localized into board.js, never
hardcoded.

**Menu-ordering constraint (v0.19.1 hotfix):** `PRAutoBlogger_Board_Page::on_register_menu`
MUST be hooked at `admin_menu` priority 11 (or higher), AFTER
`PRAutoBlogger_Admin_Page::on_register_menu` which runs at priority 10. Reason: WordPress
computes the page hookname via `get_plugin_page_hookname()` at `add_submenu_page()` call
time. If `add_menu_page()` has not yet run, `$admin_page_hooks['prautoblogger-settings']`
is unset and WordPress falls back to the `admin_page_*` hookname. At HTTP request time WP
recomputes the hookname as `prautoblogger_page_prautoblogger-board` and finds no registered
callback -> `wp_die("Invalid plugin page")` 404. The enqueue-gate in `on_enqueue_assets()`
checks `prautoblogger_page_prautoblogger-board` and would similarly never fire with the
wrong hookname (board.css/board.js would silently not load). Regression test:
`tests/unit/Admin/BoardMenuRegistrationTest.php`.


### #23: Article Dossier page — per-article inspection (v0.19.2)

The dossier is a read-only per-article admin page that exposes the full substrate view for
a single post: generation run, stage I/O, cost receipts, raw-trace toggles, editorial
decisions, and image A/B pairs. It is link-accessed only (hidden submenu) — no navigation
entry appears in the WP sidebar.

**Page registration** (`class-dossier-page.php`): uses the canonical hidden-admin-page
pattern -- registered under parent **`options.php`** at `admin_menu` priority **12**. The
hookname is `admin_page_prautoblogger-dossier` at BOTH registration time and request time,
so there is no hookname mismatch and no need to manipulate `$submenu`. The page does not
appear in the WP admin sidebar (options.php-parent pages are hidden by construction).
URL shape: `admin.php?page=prautoblogger-dossier&post_id=<int>` (unchanged from v0.19.2 --
deep-link URLs are parent-agnostic). Secured by `manage_options` capability. Static
`url_for_post(int $post_id): string` provides the canonical deep-link.

v0.19.2 used `prautoblogger-settings` as parent with post-registration `unset($submenu[...])` to
hide the page. This caused `get_admin_page_parent()` to fail at request time -> orphan
`admin_page_*` hookname -> no handler registered -> `wp_die(403)`. Fixed in v0.19.3 by switching
to `options.php` parent and removing all `$submenu` mutation. See CONVENTIONS.md §Hidden Admin
Pages for the full rule and ARCHITECTURE.md §22b for both incident records.

Regression test: `tests/unit/Admin/DossierMenuRegistrationTest.php` -- the
`test_request_time_hookname_matches_registration_hookname` test replicates the request-time
resolution algorithm (simulate `get_admin_page_parent()`) so it FAILS on hide-by-unset and
PASSES on options.php-parent.

**5-query data assembly** (`class-dossier-data-assembler.php`):

```
1. wp_prautoblogger_runs       WHERE run_id = %s             -- 1 row (run metadata)
2. wp_prautoblogger_run_stages WHERE run_id = %s             -- N rows (per-stage status/cost)
3. wp_prautoblogger_generation_log WHERE run_id = %s         -- N rows (model/pv/tokens/request)
4. wp_prautoblogger_run_decisions WHERE run_id = %s          -- N rows (editorial rationale)
5. get_post_meta($post_id)                                    -- WP object cache hit
```

All queries are keyed on `run_id` (indexed). No N+1. Stage output is reconstructed from
`run_stages.meta_json → json_decode → meta['output']` (same path as `Run_Stage_State::
get_output()`). The dossier assembler does NOT call `Run_Stage_State` directly; it performs
its own raw queries so the view is not coupled to the cache layer.

**Legacy graceful state (binding 5):** Posts published before v0.18.0 (no runs/run_stages
rows) and posts where `_prautoblogger_run_id` is absent or blank return `['has_run' => false]`.
The template renders a clean "No generation record for this article" notice. No PHP notices
or fatals.

**Amortized research rows:** `generation_log` rows with `prompt_version = NULL` and
`agent_role = ''` (from the `llm_research` stage orphan-reaper path) are rendered with
model shown as "—" and no raw-trace toggle (request_json may be NULL). No fatal/notice.

**Raw-trace security (binding 4):** `request_json` stores the OpenRouter request body —
model identifier, messages array, temperature. Authorization headers are never logged (the
outbound HTTP client strips them before logging, per the original architecture decision on
`wp_prautoblogger_generation_log`). The dossier template renders `request_json` via
`esc_html()` inside a `<pre>` block. All model-generated text rendered via `wp_kses_post()`
(rendered view) or `esc_html()` (raw trace). Both paths treat content as untrusted HTML.

**File naming note:** `PRAutoBlogger_DB_Migrations` is loaded via explicit `require_once`
in `prautoblogger.php` (not the autoloader). The autoloader converts `DB_Migrations` to the
kebab segment `d-b-migrations`, producing `class-d-b-migrations.php` — which does not match
the file `class-db-migrations.php`. Explicit loading is the canonical pattern for classes
whose names do not round-trip cleanly through the kebab converter. `class-prautoblogger.php`
uses the same pattern (also explicitly required in `prautoblogger.php`).

### #24: Edit + single-step re-run under CPO guardrails (v0.20.0, db 1.3.0, June 2026)

Phase 2 admin M3 (design contract: cpo seq 8 Proposal C + the five guardrails of the
edit+rerun delta ruling; decision `2026-06-12-admin-redesign-direction-c.md`).

**B1 — request_json persistence.** The OpenRouter provider stashes every outgoing chat
request BODY in the process-scoped `PRAutoBlogger_Request_Recorder` (the Run_Context
pattern) right after `build_body()`; `Cost_Tracker::log_api_call()` consumes it into
`generation_log.request_json`. Consume-once + overwrite-on-record means a stale body can
never attach to an unrelated row; recording happens pre-dispatch so error rows carry the
request too. Authorization can never be recorded: headers are built separately
(`build_headers()`) and `build_body()` copies only whitelisted option keys. Retention
rides the existing `prautoblogger_request_json_retention_days` prune unchanged.

**Two re-run primitives, both chained-cron (never synchronous):** the dossier AJAX layer
(`Dossier_Actions`) only validates + queues; the cron handlers (`Rerun_Executor`)
re-validate eligibility UNDER the generation lock before mutating anything.

1. **Replay** — one governed chat call from the latest edited input fork
   (`stage_inputs`, INSERT-only versions). `Stage_Replay::options_from_body()` is the
   exact inverse of `build_body()`+`apply_reasoning_budget()` (reasoning headroom
   subtracted back out; reasoning pinned explicitly so global-setting drift cannot
   reshape a replay). The call goes through `send_chat_completion`, so the cost
   governor reserves against the SAME run ledger row (guardrail 4), with the normal
   retry/empty-completion machinery. Output lands in the stage's run_stages snapshot —
   the same place downstream rebuilds read from.
2. **Rebuild ("re-run from here")** — demotes the target + downstream chain stages to
   `pending` and re-enters `Article_Worker` with the idea reconstructed from the stored
   seed (`stage_inputs`, source='seed', persisted at worker start because re-deriving
   the idea from post fields could break the item_key hash and duplicate a post).
   Upstream done stages are reused (never re-charged); demoted stages rebuild their
   prompts from CURRENT upstream snapshots — this is how an edit propagates downstream.

**Stale is a column, not a status.** A stale stage stays `done`, so
resume-without-recharge logic can never silently re-run it — guardrail 3 (no silent
auto-rerun; each downstream re-run is a deliberate operator action) holds by
construction. Only `Run_Stage_Writes::done()` clears `stale`; only
`Run_Stage_Rerun_State` (operator-action paths) may demote a done row. `human_modified`
is sticky for the life of the run (set when a fork enters execution, surfaced as header/
stage chips in the dossier, a board-card chip, and a Review Queue link-chip — the
run-list-level visibility the product AC requires).

**Run reopening.** `Run_State::reopen()` is the atomic terminal→running gate for re-run
spend; it RE-SNAPSHOTS `ceiling_usd` from the current setting (a deliberate re-run
adopts the operator's current per-run policy, exactly like a new run) and never resets
reserved/settled — re-runs are new spend on the same run. Handlers always restore a
terminal status (or record failed/halted) so the run-reaper's stuck-running sweep stays
meaningful; jobs hold the global generation lock and ride the M1 status transient, so
board/dossier polling shows queued → pickup → result and the existing R2/R3 orphan
recovery covers dead re-run processes unchanged.

**Eligibility (guardrail 5).** Posts with status publish/future/private freeze their
run server-side (and `Publisher` refuses to touch them: re-run output refreshes only
UNPUBLISHED posts, preserving their status — re-runs never publish). Editable stages
are the item-scoped writer chat stages (outline/draft/polish); review/publish re-run
via rebuild (their inputs are derived), run-level and image stages are excluded with
visible in-UI reasons. Phase-2b stages inherit the mechanism when they exist as
item-scoped chat calls.

**F2/F3 (QA M2):** the sidebar consolidates per-stage model/pv/role; image (and other
log-only) stages render from generation_log + the `_prautoblogger_image_role`
attachment set — wiring the dossier to real data was deliberately chosen over teaching
the live image pipeline to write run_stages (that belongs to the Phase-2b restructure).

## Cross-System LLM Budget Coordination

PRAutoBlogger and Peptide News both call OpenRouter and may share a single API key / billing account. Their combined spend should be considered when setting per-plugin budgets.

| Plugin | Default Models | Typical Daily Spend | Budget Control |
|--------|---------------|--------------------:|----------------|
| PRAutoBlogger | Gemini 2.5 Flash Lite (analysis + editing), Claude Sonnet 4 (writing) | $0.05–$0.30 depending on article count | Hard-stop monthly budget in plugin settings |
| Peptide News | Google Gemini 2.0 Flash (keywords + summaries) | $0.01–$0.05 | No hard budget yet (planned) |

**Important:** If your OpenRouter account has a global spending limit, set each plugin's budget to less than half the total. PRAutoBlogger will hard-stop when its budget is exhausted, but Peptide News currently has no budget enforcement — a spike in news fetches could consume shared quota.

**Future improvement:** A shared `wp_options` key (e.g., `ecosystem_monthly_llm_budget`) that both plugins read, with each plugin reserving its allocation on startup. This requires coordination at the ecosystem level and is tracked as a medium-term goal.

## §25 Chained-Cron Checkpoint Generation Path (v0.21.0, M4)

### Problem

Hostinger shared hosting kills PHP background processes after ~120 s. The former
`on_manual_generation()` path in `Executor` ran the complete orchestrate +
generate pipeline in a single PHP process — meaning a host kill mid-run left the
generation lock held, the run row stuck in `running`, and partially-generated
articles orphaned with no status broadcast.

### Solution: Two-Tick Checkpoint Machine

`PRAutoBlogger_Generation_Checkpoint_Runner` (v0.21.0) splits the pipeline into
independent cron ticks, each of which completes well within Hostinger's limit:

| Tick | WP-Cron action | What happens |
|------|---------------|-------------|
| 1 | `prautoblogger_gen_orchestrate` | Collect sources → analyze → score ideas. Serialize ideas to `prautoblogger_article_queue` option. Schedule Tick 2. |
| 2..N | `prautoblogger_gen_tick` | Pop ONE idea from queue. Call `Article_Worker::generate()`. Persist updated queue. Reschedule next Tick, or finalize. |

If the host kills any tick:
- The idea queue is persisted (option survived the crash).
- The next CRON run picks up naturally (no orphan).
- `Run_Reaper` reclaims truly stuck runs via its daily sweep.

### Entry Points

| Caller | Path |
|--------|------|
| Board "New Article" button | AJAX `prautoblogger_generate_now` → `Generation_Status_Poller::on_ajax_generate_now()` → schedules `prautoblogger_manual_generation` → `Executor::on_manual_generation()` → `kick_off()` |
| WP-CLI (async) | `wp prautoblogger generate` → `WP_CLI_Commands::generate_command()` → `kick_off()` |
| WP-CLI (sync / VPS) | `wp prautoblogger generate --sync` → `WP_CLI_Commands::run_sync()` → `set_sync_mode(true)` → `on_orchestrate_tick()` → tick loop `on_generate_tick()` (×N) → `set_sync_mode(false)` |
| Old cron hook (retained) | `prautoblogger_manual_generation` → `Executor::on_manual_generation()` → `kick_off()` |

### SSH Workaround Retirement

The former `wp eval 'do_action("prautoblogger_manual_generation")'` workaround
fired `on_manual_generation()` synchronously in the CLI PHP process, bypassing:
- Nonce/capability checks (running as CLI user, not WP user)
- Cron-based loopback spawning
- Per-process time limits

This path is retired. `Executor::on_manual_generation()` now delegates immediately
to `Generation_Checkpoint_Runner::on_orchestrate_tick()` (the Tick 1 handler),
which acquires the lock and runs orchestration within its own process. The
`wp prautoblogger generate` WP-CLI command provides the supported CLI path.

### Key State

| Store | Key | Contents |
|-------|-----|---------|
| DB option | `prautoblogger_article_queue` | `{run_id, ideas[], results{}}` — persisted across ticks |
| DB option | `prautoblogger_checkpoint_run_id` | Current run UUID (for finalize/cleanup paths) |
| Transient | `prautoblogger_generation_status` | Status broadcast (polling by board/dossier JS) |
| DB | `prautoblogger_runs` | Per-run ledger with status, cost ceiling |

### Budget / Halt Contract

`Cost_Tracker::is_budget_exceeded()` is checked at the START of Tick 1 and each
generate tick. A halted run (`Run_State::get_status() = 'halted'`) causes the
generate tick to abort without calling `Article_Worker`, clean up the queue, and
release the lock — same guarantee as `Pipeline_Runner`'s queued-article path.

# Changelog

All notable changes to PRAutoBlogger will be documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project uses [Semantic Versioning](https://semver.org/).

## [0.18.0] - 2026-06-12

Pipeline v2 — Phase 1 substrate (plan of record: CPO thread
`2026-06-prautoblogger-pipeline-redesign`, seq 3). Built on the current pipeline with **no
behavior change** to the Economy (single-pass) and multi-step publish paths.

### Added
- **Schema v1.2.0** (`prautoblogger_db_version` 1.1.0 → 1.2.0, idempotent dbDelta
  migrations, self-healing on version mismatch): new tables
  `wp_prautoblogger_prompts` (versioned prompt registry),
  `wp_prautoblogger_run_sources` + `wp_prautoblogger_run_decisions` (audit child tables),
  `wp_prautoblogger_runs` (per-run cost ledger + lifecycle) and
  `wp_prautoblogger_run_stages` (per-run per-stage state machine, idempotency key
  `run_id + stage + agent_role + item_key`); additive nullable columns `agent_role` and
  `prompt_version` on `wp_prautoblogger_generation_log` (historical rows stay valid).
- `PRAutoBlogger_Stage_Display_Map`: single PHP vocabulary for historical stages
  (`analysis`, `outline`, `draft`, `polish`, `review`, `llm_research`, `image_a`, `image_b`,
  `image_prompt_rewrite`, `opik_eval_judge`), Pipeline v2 stages (`research`, `curate`,
  `draft`, `editorial`, `seo`, `publish`) and a humanizing fallback for unknown values, plus
  per-stage default agent role and primary prompt-registry key.
- Uninstall now drops the five substrate tables and clears every plugin cron hook
  (previously only two of eight were cleared).
- **Versioned prompt registry** (`PRAutoBlogger_Prompt_Registry` + `_Writer`): all pipeline
  prompt copy — content system/single-pass/outline/draft/polish, analysis system/user,
  editor system/review, research system, plus the **illustration rewriter prompt and the
  image Style Template** (the image-composer PR seam) — is seeded as immutable version 1
  from the v0.16.0 hardcoded texts (`prautoblogger_migrated_prompt_seed_v0180`). One active
  version per key; changes create new versions, never mutate. Call sites render through the
  registry with a **byte-identical in-code fallback** when the table is missing/empty
  (self-healing, no fatals on half-migrated state). No admin UI in Phase 1.
- **Prompt-version pinning**: `Cost_Tracker::set_run_id()` pins the active version of every
  key on the run's row; every generation_log row (text and image stages) is stamped with the
  pinned `prompt_version` plus a stage-derived `agent_role`. Single-pass rows keep stage
  `draft` and stamp `content.single_pass` explicitly.
- `PRAutoBlogger_Run_State` (runs-table ledger row: ceiling/reserved/settled/overage, pins,
  sticky lifecycle states) and `PRAutoBlogger_Run_Context` (per-process run id, mirrors the
  Opik trace-context pattern).

- **Per-run cost governor** (`PRAutoBlogger_Cost_Governor`, net-new enforcement — previously
  only the monthly cap was enforced): every `send_chat_completion()` reserves its worst-case
  estimate (prompt chars/4 + max_tokens, priced via the #18 chain) against the run ledger
  with a single atomic conditional UPDATE (the #10 lock discipline) BEFORE dispatch; the
  image `curl_multi` batch reserves its summed estimate before dispatch; reservations settle
  to actuals after each response (a failed call releases its hold). Breach → run `halted`
  (sticky), overage recorded, `Cost_Ceiling_Exception` aborts the call, remaining queued
  articles abort, and the run is surfaced in a "Halted runs" notice on the Review Queue.
  New setting **Per-Run Cost Ceiling (USD)** (`prautoblogger_per_run_cost_ceiling_usd`,
  default `PRAUTOBLOGGER_DEFAULT_RUN_CEILING_USD` = $0.50, snapshotted at run start,
  0 = disabled). Calls outside a run, the monthly budget, and the Cloudflare AI Gateway
  path behave exactly as before.

### Changed
- `Content_Generator`'s four stage methods now share one `execute_stage()` helper (the Opik
  span + dispatch + cost-log boilerplate was identical); file drops from 301 to ~220 lines.
  No behavior change: same prompts, models, params, span names, stage values.
- `Cost_Tracker::get_avg_tokens_for_stages()` body moved to `Cost_Reporter` (read-only
  reporting query); the Cost_Tracker method remains as a delegating wrapper.
- `OpenRouter_Provider`'s API-key fetch + format validation moved to
  `OpenRouter_Request_Builder::resolve_api_key()` (300-line cap); identical messages/behavior.

### Fixed
- ARCHITECTURE.md documented the multi-step edit stage as `edit`; the code has always
  written `polish`. Canonicalized to **`polish`** everywhere.

## [0.17.0] - 2026-06-11

### Added
- **Deterministic PHP image composer (Commit 2 of the in-plugin editorial image
  pipeline).** Every generated image now passes through a local, $0,
  deterministic composer between the provider's bytes and the media sideloader.
  Image A yields a corner-marked featured image (1200×632) plus a branded
  **OG/social variant** (1200×630, teal band, brand mark, baked editorial
  caption in Poppins SemiBold) and a **square card variant** (1080×1080, image
  slice + cream caption panel in Poppins Bold + teal footer with the horizontal
  lockup). Image B gets the corner mark only. Variants are sideloaded with a
  role filename suffix and meta-linked: attachment meta
  `_prautoblogger_image_role` + `_prautoblogger_base_attachment_id`, post meta
  `_prautoblogger_og_image_id` + `_prautoblogger_square_image_id` (the rebuilt
  SEO stage will emit `og:image` from the frozen og key).
- **Capability ladder with cached probe.** Imagick (full compositing) →
  `wp_get_image_editor()` GD path (resize-only, unbranded, single WARNING) →
  pass-through (single WARNING). The probe result is cached in
  `prautoblogger_image_compose_capability`, fingerprinted by PHP version +
  imagick/gd presence so it auto-invalidates when the host changes. Override
  filter: `prautoblogger_image_compose_capability`. Composition failure can
  never block a publish (per-render try/catch + pipeline safety net).
- New Images settings: `prautoblogger_image_compose_enabled` (default on),
  `prautoblogger_image_compose_variants` (default `og,square`),
  `prautoblogger_image_featured_mark_enabled` (default on). Geometry ships as
  layout-class constants behind the `prautoblogger_image_compose_layout`
  filter. All keys keep the `prautoblogger_`/`_prautoblogger_` prefixes, so
  the existing uninstall prefix-sweep purges them (no uninstall.php changes).
- Vendored brand rasters (`assets/brand/`, from peptide-repo-brand SVG masters)
  and bundled OFL fonts (`assets/fonts/`: Poppins Bold + Poppins SemiBold +
  OFL.txt) — no system-font or runtime-SVG dependency, byte-stable output per
  environment (metadata stripped, PNG date/time chunks excluded).

### Fixed
- Featured/Image B **alt text is now the editorial caption** instead of the
  generation model name (`sideload_and_log()` passed the model as `$alt_text`,
  contradicting the sideloader docblock). The model keeps persisting in
  `_prautoblogger_image_model`.

### Changed
- `PRAutoBlogger_Image_Media_Sideloader::sideload_image()` /
  `generate_filename()` accept an optional filename role suffix
  (back-compat default `''`).
- `PRAutoBlogger_Image_Pipeline` delegates sideload/cost-log, caption
  prepending, and variant persistence to the new
  `PRAutoBlogger_Image_Attacher` (300-line compliance); also declares the
  previously-dynamic `$trace_context` property (PHP 8.2 deprecation).

## [0.16.0] - 2026-05-29

### Changed
- **Editorial image prompt (Commit 1 of the in-plugin editorial image pipeline).**
  The image style pivots from a single-panel newspaper comic to a text-free
  editorial scientific illustration. The rewriter LLM now emits a concise
  1-2 sentence topic/mechanism summary as the SCENE (one centered focal
  subject, no text/people-as-gag/logos), keeping the short CAPTION line for the
  unchanged HTML-caption-below-image behavior. The scene/caption parsing
  contract is unchanged. Provider/model are unchanged — production keeps
  generating via Runware FLUX.1 schnell but now produces text-free editorial
  base images. See `docs/proposals/2026-05-29-image-pipeline-in-plugin-brief.md`.
- The prompt builder now substitutes the rewriter scene into an editable style
  template's `{{ topic_summary }}` slot via the new
  `PRAutoBlogger_Image_Template_Filler`, replacing the old
  `scene + style suffix` concatenation, across all three entry points
  (`build_article_prompt`, `build_source_prompt`, `build_fallback_prompt`).
  Before substitution the scene is stripped of control characters and
  length-clamped (brief A5). If the template lacks exactly one token the
  summary is appended and a warning logged; if the summary is empty the
  style-only prompt is emitted — a blank or token-only prompt is never sent to
  the provider (brief A5/A6).

### Added
- New setting **Style Template** (`prautoblogger_image_style_template`,
  textarea) replacing the Style Suffix field. Default =
  `PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE` (the editorial template). On save
  it is validated to contain exactly one `{{ topic_summary }}` token; an invalid
  template keeps the previous value and surfaces a settings error.

### Deprecated
- `prautoblogger_image_style_suffix` is deprecated and no longer read by the
  prompt builder. A one-time migration (`prautoblogger_migrated_style_template_v0160`)
  mirrors its value to `prautoblogger_image_style_suffix_deprecated` for one
  version cycle and seeds the new template default. The old option is NOT
  deleted this cycle.

## [0.15.1] - 2026-05-14

### Fixed
- Cost preview in AI Models settings tab no longer prints a WordPress database
  error notice; query now uses canonical column names (`prompt_tokens`,
  `completion_tokens`, `created_at`). Bug present since v0.11.0 (2026-04-23).

## [0.14.0] - 2026-04-26
## [0.15.0] - 2026-04-26

### Added
- **Live Runware Model Catalog Sync**: New `class-runware-model-catalog.php`
  fetches the live Runware model list via their `/v1` endpoint (task-based API),
  filters to `taskType=imageInference` (text-to-image only, excluding inpainting),
  normalizes to the Image_Model_Registry shape, and caches in `prautoblogger_runware_model_cache`
  with a 24-hour TTL. Fixes the pattern from v0.13.8 and v0.14.0 where the
  hardcoded Runware model list was a maintenance burden and gaps (missing models,
  wrong prices) went unnoticed until customer images generation broke.
- Daily WP-Cron sync (`prautoblogger_sync_runware_models`) scheduled on activation
  and unscheduled on deactivation.
- On-demand "Sync now" AJAX button in PRAdmin (Images settings section) with
  nonce protection and `manage_options` cap check. Refreshes model count and
  last-synced timestamp in the UI without full page reload.
- Smart caching + fallback strategy: if cache is < 24h old, use it; if stale,
  trigger a sync; if sync fails, use the last-known-good cache; if no cache,
  fall back to hardcoded list (never returns empty array).
- Pricing merge: Runware API may not expose per-image cost. The catalog pulls
  costs from `class-runware-image-pricing.php::COST_PER_IMAGE` on every sync,
  ensuring pricing data is always authoritative and up-to-date.
- Unit tests for `PRAutoBlogger_Runware_Model_Catalog`: sync success/failure,
  fallback on API unreachable, stale cache behavior, cost merge logic.
- `class-image-model-registry.php` refactored: `get_models()` now delegates to
  the catalog for Runware models + static OpenRouter list. Extracted fallback
  lists into private `get_runware_fallback_models()` and `get_openrouter_models()`
  for clarity.

### Fixed
- Silent model list gaps no longer possible: Runware no longer depends on manual
  model list maintenance in the pricing class. The live catalog is the source of
  truth; pricing is merged in on every sync.

### Changed
- `class-image-model-registry.php`: `get_models()` is now a thin wrapper around
  the catalog + OpenRouter static list. Breaking change: callers that expect a
  specific static list should note the new dynamic Runware side.


### Fixed
- Runware image generation now correctly uses the admin-selected model instead
  of silently falling back to FLUX.1 schnell (runware:100@1) for all non-schnell
  and non-dev selections. Root cause: `class-runware-image-pricing.php` only
  had 2 models in its `COST_PER_IMAGE` whitelist (schnell + dev), but the
  image model registry was expanded to 15 Runware models in v0.13.8 without a
  matching update to the pricing class. Any model ID not in the whitelist caused
  `resolve_model()` to fall back to schnell, so every Stable Diffusion, FLUX.2,
  HiDream, TwinFlow, Z-Image, Qwen-Image, Krea, and GLM-Image selection was
  silently downgraded.
- Added all 15 Runware model IDs to `COST_PER_IMAGE` and `DEFAULT_STEPS` with
  correct per-image costs and step counts sourced from runware.ai/pricing (April 2026).
- Added `@see` cross-reference to `class-image-model-registry.php` in pricing
  class docblock to prevent future drift.

## [0.13.9] - 2026-04-26

### Fixed
- Model picker sort by price now works correctly for image models — previously
  \`applySort\` always read \`input_price_per_m\` (undefined on image models),
  so every image had an effective price of 0 and price sort was a no-op.
  Fix: detect image models via \`cost_per_image\` and use that field instead.

## [0.13.8] - 2026-04-26

### Added
- Image model picker expanded from 7 to 21 models
- New Runware text-to-image models: Stable Diffusion 3, HiDream-I1 Full/Dev/Fast,
  FLUX.1 Krea [dev], Qwen-Image, FLUX.2 [dev], FLUX.2 [klein] 9B, FLUX.2 [klein] 4B,
  GLM-Image, Z-Image, Z-Image-Turbo, TwinFlow Z-Image-Turbo
- New OpenRouter model: openai/gpt-5.4-image-2
- Registry now includes last-verified date and update instructions in source comment


All notable changes to PRAutoBlogger will be documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project uses [Semantic Versioning](https://semver.org/).

## [0.13.7] - 2026-04-24

### Fixed
- Posts list column layout: title was set to 35% which, combined with 3 fixed-width PRAB columns, left ~50px for every remaining column (Author, Categories, Tags, Date all word-wrapping). Replaced with a balanced budget: title 22%, author 6%, categories 7%, tags 9%, date 8%, PRAB columns 85/85/50px, Peptide Topics 75px.

## [0.13.6] - 2026-04-24

### Fixed
- Peptide Topics column still rendering vertically: WordPress posts table uses `table-layout: fixed` which ignores `min-width` entirely. Changed to explicit `width: 80px` which is respected under fixed-layout tables.

## [0.13.5] - 2026-04-24

### Fixed
- Cost column in Posts list was always showing $0.00: a PHP `// phpcs:ignore` comment was embedded inside the SQL string literal, causing a MariaDB syntax error on every query. Moved comment above the `prepare()` call.
- Peptide Topics column header in Posts list was being squeezed to near-zero width by the fixed-width PRAB columns. Added `min-width: 80px` to `.column-taxonomy-peptide_topic`.

## [0.13.4] - 2026-04-24

### Fixed
- Model picker price column now consistently right-aligned. Root cause: price and description were siblings inside .ab-mp-row-meta, with description first and variable-length — this shifted price left/right per row. Fix: moved price to last position with flex-shrink: 0 so its right edge is always pinned to the row edge; capped description at max-width 200px with ellipsis; swapped column headers to match new DOM order.

## [0.13.3] - 2026-04-24

### Fixed
- Price column header now right-aligned with `min-width: 90px` to match data cells — header and prices now track the same column edge.

## [0.13.2] - 2026-04-24

### Changed
- Model picker: added sortable column headers (Model, Price, Context); clicking a header sorts the list ascending/descending; active column highlighted with sort arrow; sort preference persists while the picker is open.

## [0.12.3] — 2026-04-24
## [0.13.1] - 2026-04-24

### Fixed
- Model picker price column now right-aligned with a minimum width so values like `$0.0006/image` and `$0.0800/image` stack cleanly instead of floating at uneven offsets.

## [0.13.0] - 2026-04-24

### Added
- **Wave 2 Eval Harness**: Complete evaluation framework for PRAutoBlogger output quality
  - Frozen eval dataset (`eval/dataset.json`) with 20 curated peptide topics
  - LLM-as-judge scorer (`class-opik-eval-scorer.php`) using Gemini Flash 1.5
  - Eval runner orchestrator (`class-opik-eval-runner.php`) with Opik experiment support
  - WP-CLI command: `wp prautoblogger opik:eval [--limit=<n>] [--dry-run]`
  - Image pipeline instrumentation: Opik span wrapping for `image_prompt_rewrite` step
- **Image Prompt Builder Refactor**: Extracted scene/caption parsing into `PRAutoBlogger_Image_Scene_Parser`
  - Three static methods: `parse_scene_and_caption()`, `extract_first_paragraph()`, `synthesize_visual_concepts_fallback()`
  - Cleaner separation of concerns; builder now ~255 lines (was 331)
- **Content Generator Eval Mode**: Added `$eval_mode` parameter to suppress publishing and image generation side effects
- **Trace Context Integration**: Optional trace instrumentation in `PRAutoBlogger_Image_Prompt_Builder` constructor

### Changed
- `PRAutoBlogger_Image_Prompt_Builder` constructor now accepts optional `PRAutoBlogger_Opik_Trace_Context` parameter
- `PRAutoBlogger_Image_Pipeline` constructor passes trace context through to prompt builder
- Image prompt rewrite LLM calls now emit Opik spans when trace context is available

### Fixed
- Image prompt builder now properly delegates to static parser methods


### Fixed
- **Opik autoloader gap** — `class-autoloader.php` listed `services/` as a scan directory but not `services/opik/`, where all four Opik classes live (`Opik_Client`, `Opik_Trace_Context`, `Opik_Span_Queue`, `Opik_Dispatcher`). Composer's classmap (used in PHPUnit) covers `includes/` recursively so tests passed, but the WordPress runtime autoloader would fatal on first Opik class reference in production. Added `services/opik/` to the scan list.


## [0.12.2] — 2026-04-24

### Fixed
- **Image style suffix empty-string bypass** — `get_option()` for `prautoblogger_image_style_suffix` only returns the default constant when the key is absent from `wp_options`; an empty string stored in the DB returns `''`, bypassing fallback entirely. Root cause of post #675 rendering photorealistically instead of as a newspaper comic. Fixed all three call sites in `class-image-prompt-builder.php` (lines 85, 107, 272) by extracting a private helper `get_style_suffix()` that treats both absent AND empty string as "use default".

- **Image cost logged under wrong run_id** — `class-image-pipeline.php` created a fresh cost tracker when invoked without an argument, so image costs were logged under a different `run_id` than the article's other stages. Cost breakdowns aggregating by `run_id` silently excluded the image line item. Fixed by threading the article worker's cost tracker through `PRAutoBlogger_Publisher` and `PRAutoBlogger_Post_Assembler::attach_generated_images()` to the image pipeline constructor. Image costs now share the same `run_id` as analysis, draft, review, and llm_research stages.


## [0.12.1] — 2026-04-23

### Fixed
- **Opik UUID v7 bug** — `PRAutoBlogger_Opik_Trace_Context` was generating trace and
  span IDs with `wp_generate_uuid4()` (UUID v4). Opik's REST API requires UUID v7 and
  rejects v4 with HTTP 400 "Trace id must be a version 7 UUID". Added a private static
  `generate_uuid7()` method using `microtime()` + `random_bytes()` and replaced both
  call sites. All trace and span submissions now succeed.

## [0.12.0] — 2026-04-23

### Added
- **Opik LLM observability integration (Wave 1: tracing skeleton).** Cloud-hosted observability via Comet Opik for detailed per-call tracing and prompt-version regression testing. Captured via async fire-and-forget dispatch to avoid blocking article generation.

- **Opik REST client.** Minimal HTTP client (no third-party SDK) with exponential backoff retry, batch endpoints, and credential isolation (API key from wp-config.php constants, never in DB).

- **Per-request trace context singleton.** Holds trace ID + span stack for one article generation. UUID threading across draft generation, editorial review, and image prompt generation.

- **Async span/trace queue.** WP options-backed queue with TTL expiry (12 hours) and max depth (1000 items). Drained by cron dispatcher with per-batch retry logic.

- **Cron dispatcher.** `prautoblogger_opik_dispatch` action scheduled via `wp_schedule_single_event()` drains queue in batches of up to 100 spans.

- **Opik admin settings section.** Toggle to enable/disable tracing (default off), project name field, opt-in full-prompt capture, read-only status display (API key configured, last dispatch, queue depth).

- **Span instrumentation at four LLM call sites:** single-pass article generation, outline/draft/polish stages (multi-step), and editorial review. Each span captures model name, provider, usage tokens.

- **Comprehensive unit test suite.** 4 test files covering client (auth, retry, batch limits), trace context (UUID generation, span stacking), queue (enqueue/dequeue, expiry, reenqueue), and dispatcher (batch POST, separation of traces vs. spans).

### Changed
- **class-article-worker.php:** Initializes Opik trace context at generation start, enqueues finalized trace + spans at end if feature enabled.

- **class-content-generator.php:** Wraps single-pass and multi-step stages (outline, draft, polish) with Opik spans capturing token usage.

- **class-chief-editor.php:** Wraps editorial review LLM call with Opik span.

### Notes
- **Feature-flag default OFF.** Zero network traffic to Opik unless `prautoblogger_opik_enabled` option is true AND API credentials are defined in wp-config.php.
- **Image prompt generation instrumentation deferred.** File already at 314 LOC (over 300-line rule); can be instrumented in Wave 2 without modifying existing code.
- **Wave 2 holds eval harness.** CLI command for frozen-dataset regression testing with LLM-as-judge scoring (separate thread after v0.12.0 ships and is live-tested).

## [0.11.0] — 2026-04-23

### Added
- **OpenRouter model picker for text models.** Admin-facing dropdown field type (`model_select`) with searchable list, capability filtering, and estimated cost preview. Replaces free-text model slug inputs on the three text model settings (analysis, writing, editor). Includes daily cron refresh of the OpenRouter registry cache and manual "Refresh Model List" button on the AI Models tab.

- **Cost preview in model picker.** Shows estimated cost per generation based on historical token usage for the 30 days prior. Maps each setting to its constituent pipeline stages (writing model = outline + draft + polish).

- **AJAX refresh endpoint** (`prautoblogger_refresh_models`) for manual model registry refresh with nonce + capability gating (`manage_options`).

- **New public method on Cost Tracker:** `get_avg_tokens_for_stages(array $stages, int $days = 30): array` returns average input/output token counts for a stage list, used by cost preview.

### Changed
- **AI Models tab now renders a "Refresh Model List" button** next to the panel title. Triggers `prautoblogger_refresh_models` AJAX endpoint.

## [0.10.1] — 2026-04-23

### Fixed
- **PHPCS: closed the last 26 WordPress-Core residuals** (15 short ternaries rewritten, 11 targeted ignores on false-positive ternary-with-prepare in ideas-browser + logger).

- **Tests: OpenRouterImageProviderTest now round-trips through real Encryption class.**
  setUp was using a dead `eval()` mock that never installed because the real `PRAutoBlogger_Encryption`
  class was autoloaded first. Tests now call `PRAutoBlogger_Encryption::encrypt()` directly,
  leveraging the BaseTestCase-stubbed `wp_salt()` for deterministic round-trip decryption.
  Fixes both `test_generate_image_success` and `test_generate_image_4xx_throws`.
- **Tests: add missing `post_type_exists()` stub to BaseTestCase.**
  When PR Core is not active, PeptideLinker guards calls with `post_type_exists()`.
  This WordPress function was not mocked in the base test setup, causing 8 PublisherTest errors.

### Changed

- **PHPCS: backlog substantially reduced (1,292 → 28 violations).**
  Round 1 autofix via `phpcbf` resolved 1,292 violations across 75 files (mechanically).
  Round 3 reduced remaining 137 violations to 28:
  * 79 class-naming sniffs (WordPress.Files.FileName) — excluded in phpcs.xml; architectural decision to use short class names (class-logger.php) instead of fully-qualified names for readability.
  * 56 `$wpdb->prepare()` violations (WordPress.DB.PreparedSQL) — excluded in phpcs.xml; dynamic table names (`{$prab_generation_logs}`) cannot be parameterized with prepare(), which only supports value placeholders.
  * 28 remaining violations (other sniffs) — mostly in class-logger.php, class-ideas-browser.php; deferred for future cleanup.
  Excluded sniffs are documented in phpcs.xml with architectural justifications.
- **CI: PHPCS gate now strict.** Changed `continue-on-error: true → false` in `.github/workflows/ci.yml`.
  PHPCS failures will now block CI, preventing style regression.

## [0.10.0] — 2026-04-21

### Removed

- **Cloudflare Workers AI as image provider.** The four `class-cloudflare-image-*`
  provider files are gone along with their unit tests and the three
  CF-specific options (`prautoblogger_cloudflare_ai_token`,
  `prautoblogger_cloudflare_account_id`, `prautoblogger_cf_image_via_gateway`).
  Runware FLUX.1 schnell produces equivalent-quality output at ~65×
  less cost and eliminates a duplicated settings surface. The
  `flux-1-schnell` registry entry that mapped to the CF provider is
  removed; the legacy slug now resolves to an empty provider (caught
  by the migration).

### Changed

- **Sites on any `cloudflare` image provider auto-migrate to
  `runware:100@1` on upgrade.** New migration
  `PRAutoBlogger_Migrate_Remove_Cloudflare_V0100::run()` runs from
  `admin_init` once per site. Detects three legacy shapes: registry-
  derived provider `cloudflare`, `prautoblogger_image_provider` option
  set to `cloudflare`, or the legacy slug `flux-1-schnell`. Sets
  `prautoblogger_image_provider` + `prautoblogger_image_model` to the
  Runware schnell pair and deletes the three dead CF options. Idempotent
  via `prautoblogger_migrated_remove_cloudflare_v0100` flag — running
  twice is a no-op and will not clobber a post-migration manual choice.
- **Image provider dispatch fallback is now Runware, not Cloudflare.**
  `PRAutoBlogger_Image_Pipeline::create_default_provider()` returns
  `PRAutoBlogger_Runware_Image_Provider` for any unrecognised
  `prautoblogger_image_provider` value. An unmigrated install still
  gets a working pipeline instead of a fatal.

### Note

- **Cloudflare AI Gateway fronting OpenRouter is unaffected.** The
  `prautoblogger_ai_gateway_base_url` + `prautoblogger_ai_gateway_cache_ttl`
  options, their settings fields, `class-open-router-config.php`
  gateway resolution, and `class-open-router-request-builder.php`
  `cf-aig-cache-ttl` headers are all intentionally untouched. The AI
  Gateway is a proxy/cache layer in front of OpenRouter and is
  orthogonal to the CF Workers AI image provider removal. The
  migration test asserts this invariant explicitly.

### Cost impact

Zero recurring spend from CF Workers AI image generation (the
peptiderepo.com install was still on CF as recently as v0.8.2 — see
commit `80a7e11`). No new prompt tokens, no change in model mix for
sites already on Runware or OpenRouter.

### Migration checklist (for anyone else running this plugin)

1. On first admin_init after upgrade, the migration will flip
   `prautoblogger_image_provider` to `runware` and
   `prautoblogger_image_model` to `runware:100@1` if you were on CF.
2. **Set `prautoblogger_runware_api_key`** in Settings → Images if you
   hadn't already — image generation will fail until it is set.
3. The CF API token + account ID options are deleted by the migration;
   you don't need to do anything.
4. If you want to switch back to OpenRouter (Gemini) instead of
   Runware, just change the Image Model picker after the migration
   has run.

## [0.9.0] — 2026-04-21

### Added

- **Runware (FLUX.1 schnell) as the default image provider.** New
  provider at `includes/providers/class-runware-image-*` generates
  ~$0.0006/image on FLUX.1 schnell (`runware:100@1`) — roughly 65× cheaper
  than the previous default (Gemini 2.5 Flash Image via OpenRouter at
  ~$0.039/image). FLUX.1 dev (`runware:101@1`, ~$0.02/image) is also
  available for higher-fidelity runs. The provider mirrors the
  OpenRouter split: interface + provider + support + pricing + batch,
  all under the 300-line cap.
- **True parallel image generation.** `PRAutoBlogger_Runware_Image_Batch`
  uses `curl_multi_init/exec/select` to fire all imageInference POSTs
  concurrently, then downloads the signed URLs concurrently. Wall-clock
  for the A/B pair drops to ≈ the slowest single image instead of the
  sum of both.
- **v0.9.0 one-time migration.** Sites where the image model is empty
  or still pinned to `google/gemini-2.5-flash-image` are auto-flipped
  to `runware:100@1` on upgrade, with the provider re-derived from the
  registry. Flag: `prautoblogger_migrated_default_image_v090`. The
  Runware API key option (`prautoblogger_runware_api_key`) was added
  to the `enc:`-prefix migration list so Settings writes go through
  the same encryption-at-rest path as the other provider keys.

### Changed

- **Image prompt builder steered away from abstract/chemical subjects.**
  The rewriter system prompt now explicitly refuses to personify
  molecules, peptides, hormones, or proteins — FLUX (and most diffusion
  image models) render "anthropomorphic peptide" as an incoherent blob.
  Default scene guidance is now "concrete, human-scale scenes: people
  reacting to things, doctors and patients, gym bros with vials, etc."
  Style suffix unchanged — single-panel comic aesthetic preserved. See
  `includes/core/class-image-prompt-builder.php::REWRITER_SYSTEM_PROMPT`.
- **Default image model flipped to `runware:100@1` (FLUX.1 schnell).**
  `PRAUTOBLOGGER_DEFAULT_IMAGE_PROVIDER` and
  `PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL` constants in `prautoblogger.php`
  updated. Cloudflare flux-1-schnell remains in the registry as a legacy
  fallback for any operators who set it explicitly. Rationale:
  `image-style` memory (2026-04-21) — schnell passed the comic A/B
  round and the cost delta funds more generations for the same monthly
  budget.

### Cost impact

At 50 articles/day × 2 image A/B = 100 images/day:

- Before (Gemini 2.5 Flash Image via OpenRouter): ≈ $3.90/day, ≈ $117/mo
- After (FLUX.1 schnell via Runware):             ≈ $0.06/day, ≈ $1.80/mo

Net saving: ~$115/mo at current traffic, funded by a single provider
swap with no user-visible change in article quality.

## [0.8.2] — 2026-04-21

### Fixed

- **Daily generation schedule now honors site timezone.** Previously
  the time configured in Settings → Schedule was interpreted as UTC
  (WordPress core forces `date_default_timezone_set('UTC')`), causing
  runs to fire N hours off from user intent where N is the site's UTC
  offset. On peptiderepo.com (Asia/Singapore, UTC+8), a `schedule_time`
  of `06:00` fired at 14:00 local. An on-upgrade one-shot migration
  (`prautoblogger_migrated_schedule_tz_v082`) reschedules the existing
  daily cron in the site's configured timezone — no user action
  required.

### Changed

- **Cloudflare Workers AI image calls now route through the AI Gateway
  by default.** The 2026-04-15 regression that caused the gateway route
  to 403 on Workers AI has been resolved upstream. Routing via the
  gateway gives us response caching, unified cost/latency dashboard,
  and rate limiting for free, mirroring the OpenRouter LLM path. A new
  admin toggle `prautoblogger_cf_image_via_gateway` (default on) lets
  operators force direct-API if the gateway route regresses again,
  without clearing the gateway URL setting the LLM provider depends on.
- **"Generation Time" settings description** now names the site's
  configured timezone explicitly (from `wp_timezone_string()`) instead
  of the ambiguous "server timezone" label — operators can see at a
  glance which zone their input is being interpreted in.

## [0.8.1] — 2026-04-21

### Fixed

- **Orphaned LLM research costs now reaped daily.** When a pipeline died
  between post creation and the final cost-amortization step (e.g.
  execution-time kill on shared hosting), the `llm_research` row stayed
  unlinked and the affected articles' cost popovers understated true spend.
  A new daily cron (`prautoblogger_reap_orphan_research_rows`, 03:15
  server time) retroactively amortizes orphan rows against posts from
  the same run. Includes a one-time activator migration to back-populate
  `_prautoblogger_run_id` post_meta for existing posts.
- **GA4 Property ID admin description corrected.** The field description
  previously instructed users to enter `properties/XXXXXXXXX`, but the
  GA4 client already prepends the `properties/` URL segment internally —
  a double-prefixed value would 404. Description now asks for the digits
  only, matching actual code behavior.

### Added

- New WP-CLI command `wp prautoblogger reap-research` for manual reaping
  and ops debugging.
- `Publisher::build_meta()` now writes `_prautoblogger_run_id` on every
  generated post, so the reaper has a direct meta path from run_id to
  sibling posts without walking `wp_prautoblogger_generation_log`.

## [0.8.0] — 2026-04-21

### Changed

- **Single Image Model dropdown in admin → Images.** The separate Image
  Provider select has been removed. The provider (OpenRouter or
  Cloudflare Workers AI) is now derived from the chosen model's entry
  in `PRAutoBlogger_Image_Model_Registry` on save, so mismatched
  provider/model pairs — the root cause of posts 650 and 657 silently
  missing their featured image on 2026-04-20 — are no longer possible.
  A one-shot migration runs on first admin_init after upgrade and
  auto-heals any existing site where the saved provider and model
  disagreed. The `prautoblogger_image_provider` option key is
  preserved; all existing reads in the pipeline continue to work.

### Added

- **Image Prompt Instructions setting.** The system prompt used to
  rewrite articles into SCENE + CAPTION is now editable from admin →
  Images. Default preserves prior behavior; edit to reshape the
  creative direction without a code change.
- **Retry NSFW-Blocked Images setting** (default on). When the image
  provider rejects a prompt as NSFW — Cloudflare Workers AI returns
  HTTP 400 with `errors[].code === 3030` on FLUX.1 schnell —
  PRAutoBlogger now retries the slot once with a rule-based fallback
  prompt (article title + style suffix, no LLM rewrite). On a second
  block it logs a WARNING and publishes without that image, matching
  existing behaviour for any unrecoverable image failure.

### Fixed

- **Silent per-image failures in the event log.** The OpenRouter batch
  provider now emits `Logger::error` with the request key for every
  per-slot HTTP/cURL/parse failure (previously logged the error but
  not which slot it was), and `Image_Pipeline::process_image_a/_b`
  emit `Logger::warning` on `['error' => …]` results whether or not
  the outer catch fires. Operators can now see NSFW blocks, 4xx
  bodies, and timeouts in the admin Event Log without cross-
  referencing PHP error_log timestamps.

## [0.7.3] — 2026-04-20

### Fixed
- **Table colors broken in dark mode.** Replaced hardcoded hex colors
  with theme CSS custom properties (`--color-border-default`,
  `--color-bg-secondary`, `--color-text-primary`) so table borders,
  header backgrounds, and text adapt to light/dark mode automatically.

## [0.7.2] — 2026-04-20

### Fixed
- **Bullet points missing in articles.** Theme's global CSS reset strips
  `list-style` from all `ul`/`ol`. Typography CSS now restores disc/decimal
  bullets with proper padding inside `.entry-content`.
- **Tables break on mobile.** Tables now wrapped in a horizontally
  scrollable container (`prab-table-wrap`) via `the_content` filter.
  Added responsive media query for tighter padding and smaller font at
  narrow viewports.

## [0.7.1] — 2026-04-20

### Fixed
- **Typography CSS not applying.** Two bugs: CSS targeted `.post-content`
  but the theme template uses `.entry-content`, and the
  `_prautoblogger_generated` meta gate excluded posts that predate the
  meta flag. Display settings now apply to all single post pages and
  target the correct `.entry-content` selector.

## [0.7.0] — 2026-04-20

### Added
- **Display settings section.** New "Display" tab in admin with three
  settings that control how generated articles render on the frontend:
  - **Article Font** — choose from 7 font families (Inter, Georgia,
    Merriweather, Lora, Open Sans, Roboto, System) or keep theme default.
    Google Fonts are loaded only on PRAutoBlogger posts that need them.
  - **Article Font Size** — set body text size in pixels (0 = theme
    default). Recommended 16–18px for comfortable reading.
  - **Table Borders** — adds visible borders, cell padding, header
    background, and alternating row colors to tables. Enabled by default.
- **Article_Typography frontend class.** Injects inline CSS only on
  singular post pages with `_prautoblogger_generated` meta — zero
  impact on non-PRAutoBlogger pages.

## [0.6.3] — 2026-04-20

### Fixed
- **Unchecking all sources still used Reddit.** Browser omits checkbox fields
  from POST when none are checked, so the `prautoblogger_enabled_sources`
  option never updated. Added a hidden input so an empty value is always
  submitted — unchecking all sources now correctly saves `[]`.
- **Pipeline status hardcoded "Reddit".** The "Collecting sources…" stage
  message now reflects the actually-enabled sources instead of always
  saying "Reddit".

## [0.6.2] — 2026-04-20

### Added
- **Deterministic peptide auto-linker.** New `Peptide_Linker` class scans
  generated HTML and wraps every mention of a known peptide in a hyperlink
  to its `/peptides/{slug}/` database page. Runs as a post-processing step
  in Publisher before `wp_insert_post` — no LLM involvement, 100% reliable.
  Handles case-insensitive matching, hyphen/space variants (e.g., "BPC-157"
  and "BPC 157"), and skips text already inside `<a>` tags. Gracefully
  no-ops if PR Core is not active.

### Changed
- **Removed prompt-based peptide linking.** The system prompt no longer asks
  the LLM to link peptides (unreliable). Peptide links are now injected
  deterministically by `Peptide_Linker` after content generation. The prompt
  still provides article links and the "never fabricate URLs" rule.

## [0.6.1] — 2026-04-20

### Added
- **Peptide auto-linking.** When any peptide name from the PR Core database is
  mentioned in a generated article, the first mention is automatically linked to
  the corresponding `/peptides/{slug}/` page. The system prompt now includes a
  complete peptide database reference list alongside the existing article links.
  Gracefully degrades if PR Core is not active (`post_type_exists()` guard).

### Changed
- **Extracted prompt builders into Content_Prompts class.** Moved all prompt
  construction (system prompt, stage prompts, linking rules) from
  `Content_Generator` into a new `Content_Prompts` static helper. Generator
  dropped from 324 → 162 lines; prompts class is 252 lines. Both under 300.
- **Internal link rules strengthened.** Linking rules section now explicitly
  instructs the model to link every peptide's first mention and never fabricate
  URLs, with real published article and peptide page URLs provided.

## [0.6.0] — 2026-04-20

### Added
- **Generate article from idea.** New "Generate" column in the Ideas browser.
  Click any idea's Generate button to produce an article directly, bypassing
  the scheduled pipeline's collect → analyze → score steps. The button shows
  real-time stage updates (drafting, editing, publishing) via per-idea status
  polling, then swaps to Edit/View links with cost on completion.
  - `Ideas_Browser::on_ajax_generate_from_idea()` — AJAX trigger that stores
    the idea and schedules a one-shot WP-Cron event.
  - `Ideas_Browser::on_cron_generate_from_idea()` — Background handler that
    runs Article_Worker for the selected idea.
  - `Ideas_Browser::on_ajax_idea_gen_status()` — Per-idea status polling.
  - `assets/js/ideas-browser.js` — Button click, AJAX, polling, and UI state.
  - Per-idea transients (`prab_idea_gen_{id}`) for independent status tracking.
  - Page-load polling resume for ideas that were mid-generation.
  - Generation lock respected — only one generation at a time.

## [0.5.3] — 2026-04-20

### Fixed
- **Writing instructions not obeyed by LLM.** The user-configured writing
  instructions (bullet points, hyperlinks, style rules) were appended to the
  system prompt as a weak "Additional instructions:" afterthought. Models
  prioritized competing format directives in the user prompts and ignored
  the style guide.
  - System prompt now frames writing instructions as a "MANDATORY STYLE GUIDE"
    with explicit override language.
  - Every stage's user prompt (outline, draft, single-pass) now includes a
    reinforcement line: "Follow EVERY requirement from your system prompt
    style guide."
  - Polish stage explicitly preserves bullet points, numbered lists, and
    hyperlinks instead of flattening them.
  - Chief editor's revision rules now preserve structural formatting (lists,
    links) from the original draft.

## [0.5.2] — 2026-04-20

### Added
- **LLM research cost amortization.** The research LLM call (which runs once
  per pipeline execution) is now divided evenly across all articles produced
  in that run. Each article's cost breakdown popover shows its amortized share
  of the research overhead. Previously, research costs were orphaned with no
  post attribution.
  - `Post_Assembler::amortize_research_costs()` — Queries the unlinked
    research log entry, divides cost and tokens by article count, inserts one
    row per article, and removes the original.
  - `link_generation_logs()` now excludes `llm_research` stage from per-article
    linking so the cost isn't grabbed wholesale by the first article.
  - `Source_Collector::set_cost_tracker()` — Accepts the pipeline's cost
    tracker so research costs are tagged with the pipeline's `run_id`.
  - `LLM_Research_Provider` now uses the pipeline's cost tracker when available,
    ensuring research log entries share the same `run_id` as article entries.
  - Amortization runs after all articles complete (both single-article and
    chained-job paths in `Pipeline_Runner`).

## [0.5.1] — 2026-04-20

### Added
- **Ideas browser admin page.** New "Ideas" submenu under PRAutoBlogger showing
  all article ideas collected by the content analyzer. Displays suggested title,
  topic, type (question/complaint/comparison/news/guide), relevance score bar,
  frequency, key points, and target keywords. Filterable by type with count
  badges. Paginated at 30 per page, newest first.

## [0.5.0] — 2026-04-20

### Added
- **LLM Deep Research source provider.** New data source that sends a configurable
  research prompt to a reasoning-capable model (e.g. Grok 4.1 Fast, DeepSeek-R1)
  to identify trending topics, emerging questions, misconceptions, and content gaps.
  Findings feed into the content analyzer alongside Reddit data, giving the pipeline
  both real-time community signals AND deep knowledge-base research.
  - `includes/providers/class-llm-research-provider.php` — Source provider implementation.
  - New admin settings in Sources tab:
    - "LLM Deep Research" checkbox in Enabled Sources.
    - "Research Model" model picker — pick a reasoning-capable model.
    - "Research Prompt" textarea — customizable research brief with `{niche}` placeholder.
  - Each research run produces 8-12 findings stored as `llm_research` source data.
  - Deduplication via date-based source IDs (one fresh run per day, refreshes on re-run).
  - Cost tracked as `llm_research` stage in generation logs.
- Content analyzer now formats LLM research findings with a cleaner label
  (`[LLM Research] Relevance: N`) instead of the Reddit-specific `r/subreddit` format.

## [0.4.9] — 2026-04-19

### Added
- **OpenRouter reasoning mode support.** New "Enable Reasoning" toggle and
  "Reasoning Effort" selector in AI Models settings. When enabled, sends
  `reasoning: {enabled: true, effort: "<level>"}` to models that support it
  (Grok 4.1 Fast, DeepSeek-R1, etc.). Effort levels: Extra High, High,
  Medium, Low, Minimal. Reasoning tokens are billed as output tokens — cost
  is automatically tracked. Models that don't support reasoning ignore the
  parameter. Per-call override available via the `reasoning` key in the
  provider options array.
- Response parser now captures `reasoning_tokens` and `reasoning_content`
  from the OpenRouter response for downstream logging and debugging.

## [0.4.8] — 2026-04-19

### Fixed
- **GLM 4.7 Flash timeout during analysis.** The model was timing out at 120s on
  large analysis prompts (especially with the new custom instructions). Bumped
  API timeout from 120s to 180s.
- **Empty raw response in error logs.** Parse failure errors now include the raw
  LLM response inline (first 500 chars) at error level, not debug level, so
  the actual failure reason is always visible in the Activity Log.

## [0.4.7] — 2026-04-19

### Added
- **Cost breakdown popover.** The Cost column in the post list is now clickable.
  Hovering or clicking shows a per-step breakdown: stage name, model used, token
  count, and cost for every LLM call that produced the article.

## [0.4.6] — 2026-04-19

### Added
- **Image B toggle.** New "Generate Second Image (B)" toggle in Images settings.
  Disabling saves one image generation call + one LLM prompt rewrite per article.
  Defaults to enabled for backwards compatibility.

## [0.4.5] — 2026-04-19

### Added
- **Analysis Instructions setting.** Custom instructions appended to the analysis
  LLM's system prompt. Steers how source data is evaluated and which topic ideas
  get surfaced.
- **Editor Instructions setting.** Custom instructions appended to the chief
  editor's system prompt. Controls what the editorial review looks for and how
  it decides to approve, revise, or reject articles.

## [0.4.4] — 2026-04-19

### Added
- **Writing Instructions setting.** New textarea in Content settings lets you
  provide custom instructions appended to the LLM's system prompt when writing
  articles. Use it to steer style, structure, voice, and formatting without
  editing code.

## [0.4.3] — 2026-04-19

### Fixed
- **"Analysis response was not valid JSON" with some models.** Models like
  GPT 5.1 Nano ignore `response_format: json_object` and wrap JSON in markdown
  fences or preamble text. New `PRAutoBlogger_Json_Extractor` utility strips
  fences and extracts the outermost JSON object. Applied to both the content
  analyzer and chief editor parsers. Raw response now logged on failure for
  debugging.

## [0.4.2] — 2026-04-19

### Fixed
- **Posts list Title column unreadable.** Title was compressed to ~30px by the
  three custom columns. Title now gets 35% minimum width with word-wrap enabled.
  Model columns trimmed to 100px with text-overflow ellipsis for long model names.

## [0.4.1] — 2026-04-19

### Added
- **Posts list columns: Writing Model, Image Model, Cost.** Three new columns
  on the WordPress Posts admin page. Writing Model shows the LLM that wrote the
  article, Image Model shows the model that generated the featured image, and
  Cost shows the total API spend for generating that article (summed from the
  generation log). All columns show "—" for non-generated posts. Writing Model
  is sortable. Model names are shortened (provider prefix stripped) with full
  ID in a tooltip.

## [0.4.0] — 2026-04-19

### Added
- **Semantic dedup via embedding cosine similarity.** Replaces keyword-overlap
  dedup (60% word match) with MiniLM-L12-v2 embeddings via OpenRouter. Catches
  rephrasings like "BPC-157 dosing" vs "how much BPC-157 to take" that keyword
  matching misses. Automatic fallback to keywords if embedding API unavailable.
  Cost: ~$0.00001 per generation run.
  - `includes/core/class-semantic-dedup.php` — Dedup engine with embedding +
    keyword fallback.
  - `includes/providers/class-open-router-embedding-provider.php` — OpenRouter
    `/embeddings` client with batch support and cosine similarity helper.

- **LLM-aware topic avoidance.** Analysis prompt now includes the last 30 days
  of published article titles with explicit "do not suggest similar topics"
  instruction. Zero additional cost — appended to the existing analysis call.

### Changed
- Dedup window widened from 7 days to 30 days (semantic similarity is precise
  enough to avoid over-blocking).
- `class-idea-scorer.php` refactored to delegate dedup to `Semantic_Dedup`.

### Fixed
- Featured images displayed at 300×300 (WordPress "medium" size) instead of full
  width. Changed theme to use `the_post_thumbnail('full')` with responsive CSS.
- Race condition causing duplicate article generation when the AJAX status poller
  re-scheduled a cron event for an article already being generated.
- False stall detection on multi-article runs (measured elapsed time from start
  instead of idle time since last progress update).

## [Unreleased]

### Added
- **Image pipeline integration: wires image generation into article flow** (commit 1b).
  Completes the image + Instagram A/B experiment workstream by adding three
  orchestration classes that generate and sideload two images per published post.
  - `includes/core/class-image-pipeline.php` — Orchestrates A/B image generation:
    generates Image A (article-driven prompt) as featured image and Image B
    (source-driven prompt) stored in post meta `_prautoblogger_image_b_id`. Each
    image is independently fallible; article publishes even if both fail.
  - `includes/core/class-image-prompt-builder.php` — Synthesizes visual prompts
    from article title + first paragraph (for Image A) and Reddit thread title +
    top comment (for Image B). Both prompts append the CEO-locked 90s infomercial
    style suffix from settings. Prompts kept concise (under 200 words) for FLUX.1
    generation quality.
  - `includes/core/class-image-media-sideloader.php` — Downloads generated image
    bytes, creates temporary file, imports via `media_handle_sideload()`, sets alt
    text and generation metadata (`_prautoblogger_image_*`), cleans up temp files.
  - Settings: `prautoblogger_image_enabled` (toggle, default off) — allows users to
    enable/disable image generation after providing Cloudflare credentials.
  - `PRAutoBlogger_Publisher::attach_generated_images()` — New private method that
    runs the image pipeline post-creation and sets `_thumbnail_id` (Image A) and
    `_prautoblogger_image_b_id` (Image B) post meta. Errors are logged but do not
    block post publication.
  - Tests: `tests/unit/Core/ImagePipelineTest.php` (orchestration, cost tracking,
    graceful failure modes), `tests/unit/Core/ImagePromptBuilderTest.php` (prompt
    generation from various article/source shapes, length limits).
- ARCHITECTURE.md: added new image pipeline step (6b) to data flow, three new files
  to core/ section of file tree.

- **OpenRouter model registry (model-picker commit 1).** Foundation for the
  smart model picker: fetches, normalizes, and caches the OpenRouter model
  list (`/api/v1/models` — free, unauthenticated). Daily refresh via the
  existing `prautoblogger_daily_generation` cron hook with 12h idempotency.
  - `includes/services/interface-model-registry.php` — Phase 3-aware contract.
  - `includes/services/class-open-router-model-registry.php` — fetch + cache + query.
  - `includes/services/class-open-router-model-normalizer.php` — raw → standardized shape.
  - Capability vocabulary: `text→text`, `text+image→text`, `text→embedding`, etc.
  - Zero-coupling: no PRAUTOBLOGGER_* constants inside the class — Phase 2 lift
    into a shared Composer package requires only a namespace rename.
  - Cost impact: $0/month (free endpoint, no LLM tokens).

- **Image provider: Cloudflare Workers AI (FLUX.1 family).** Ships the provider, its
  pricing + validator helpers, four new settings fields in a new "Images"
  admin section, and unit tests with mocked HTTP.
  - `includes/providers/interface-image-provider.php` — contract for any image provider.
  - `includes/providers/class-cloudflare-image-provider.php` — FLUX on Workers
    AI, direct call to `/accounts/{id}/ai/run/...` (bypassing AI Gateway per
    decision D-001); exponential-backoff retries on 429 / 5xx / network,
    loud fail on 4xx; handles both raw-bytes and JSON-envelope response shapes.
  - `includes/providers/class-cloudflare-image-pricing.php` — model alias →
    full Workers AI id resolution + per-megapixel cost estimation.
  - `includes/providers/class-cloudflare-image-validator.php` — non-destructive
    "Test Connection" credential check that never generates a real image.
  - Settings: `prautoblogger_cloudflare_ai_token` (encrypted),
    `prautoblogger_cloudflare_account_id`, `prautoblogger_image_model`
    (schnell / dev), `prautoblogger_image_style_suffix` (default = CEO-locked
    90s infomercial prompt).
  - Constants: `PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL`,
    `PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX`.
  - Tests: `tests/unit/Providers/CloudflareImageProviderTest.php`.
- ARCHITECTURE.md: new key decision #16 (image pipeline: Cloudflare Workers
  AI), new external API integration row, new options rows, file tree updates.
- CONVENTIONS.md: new "How To: Add a New Image Provider" section.

## [0.2.2] — 2026-04-12

### Changed
- **CI/CD pipeline now runs PHPCS and PHPUnit** before deploying.
  Added `shivammathur/setup-php@v2` for PHP 8.1, Composer install,
  WordPress Coding Standards check, and full PHPUnit test suite.
  PHPCS runs in report-only mode (`|| true`) while codebase is
  brought into full compliance; PHPUnit failures block deploy.
- Removed stub source providers (TikTok, Instagram, YouTube).
  These were dead code — not registered in Source_Collector, threw
  RuntimeException if enabled, and confused AI agents reading the
  codebase. The `Source_Provider_Interface` remains the contract for
  adding future platforms (see CONVENTIONS.md).
- Removed "coming soon" checkboxes from Enabled Sources setting.
- Updated ARCHITECTURE.md: removed stub file references, added
  cross-system LLM budget coordination section documenting how
  PRAutoBlogger and Peptide News share an OpenRouter account.

### Improved
- **PublisherTest rewritten with real behavioral assertions.**
  Now tests: post_status ('publish' vs 'draft'), generation metadata
  storage, title from idea, taxonomy assignment, tag assignment,
  run_id-based log linking, RuntimeException on WP_Error, action/filter
  hook firing. Replaces previous method-existence-only checks.

## [0.2.1] — 2026-04-12

### Changed
- **Reddit data source switched to RSS primary, .json fallback.**
  PullPush.io was frequently stale/unavailable. Reddit RSS/Atom feeds work
  reliably from datacenter IPs (Hostinger) where .json gets 403. Comments
  are still fetched via .json (unavailable in RSS).
- Updated "Enabled Sources" checkbox label to "Reddit (RSS + .json)".
- Updated "Test Connections" to show RSS + .json status.
- Source status indicator now shows Reddit RSS (Primary) and .json (Fallback).

### Removed
- `class-pull-push-client.php` — replaced by RSS feeds in `class-reddit-json-client.php`.
- All PullPush.io references from admin UI, docs, and provider code.

### Fixed
- Generate Now button now always sends `force: '1'` to clear stale generation locks.
- Fixed encryption double-encryption bug with `enc:` prefix detection.

## [0.2.0] — 2026-04-12

### Changed
- **Reddit data source migrated from OAuth to RSS + .json (no auth required).**
  Reddit rejected our API application; RSS feeds and .json endpoints are free
  and require no authentication.
- Removed Reddit Client ID and Client Secret fields from admin API Keys tab.
- Updated "Test Connections" results to show Reddit source status.

### Added
- Source status indicator in API Keys tab showing RSS and .json
  availability with live status dots and last-collection timestamp.
- Configurable research cache TTL (1–72 hours) in Sources tab.
- Reddit time window selector (24h / week / month) in Sources tab.
- Posts-per-subreddit limit (5–100) in Sources tab.
- LiteSpeed cache purge step in CI/CD deploy pipeline.

### Removed
- `class-reddit-api-client.php` — replaced by `class-reddit-json-client.php`.
- Reddit OAuth credential fields and encrypted storage entry.

### Fixed
- Deploy pipeline now purges LiteSpeed/OPcache after file extraction,
  preventing stale PHP bytecode from being served.

## [0.1.0] — 2026-04-10

### Added
- Initial plugin scaffold with WordPress-native architecture.
- OpenRouter LLM provider with configurable model selection and retry logic.
- Reddit API source provider (OAuth2 script app) for collecting posts and comments.
- Content analysis engine: detects recurring questions, complaints, and comparisons.
- Idea scorer with deduplication against existing posts.
- Configurable writer agent pipeline (single-pass or multi-step: outline → draft → polish).
- Chief editor agent for LLM-powered editorial review before publishing.
- Publisher with full generation metadata stored as post_meta.
- Cost tracker with per-call logging, monthly budget enforcement, and hard-stop.
- Metrics collector: WordPress native + GA4 integration + composite content scoring.
- Admin settings page with sections for API keys, models, content, sources, schedule, and analytics.
- Metrics dashboard showing monthly spend, budget utilization, cost by stage, and daily spend.
- Post metabox showing generation metadata on PRAutoBlogger-generated posts.
- Admin notices for missing API keys, budget warnings, and configuration hints.
- Stub providers for TikTok, Instagram, and YouTube (future implementation).
- Encrypted storage for API keys using AES-256-CBC with wp_salt().
- AJAX-powered "Generate Now" and "Test Connections" buttons.
- Custom database tables for source data, analysis results, generation logs, and content scores.
- Clean uninstall handler removing all plugin data.
- ARCHITECTURE.md and CONVENTIONS.md for AI-readable codebase documentation.

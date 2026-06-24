# Changelog

All notable changes to PRAutoBlogger will be documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project uses [Semantic Versioning](https://semver.org/).

## [0.31.0] - 2026-06-24

### Added
- **P2b.4 — tier routing + Authority pipeline orchestrator + citation/imagery gates**
  - `PRAutoBlogger_Tier_Router` (`core/class-tier-router.php`, 100 lines): resolves Authority vs Economy per-category. Master switch `prautoblogger_authority_pipeline_enabled` defaults FALSE — flag OFF means ALL generation uses the Economy single-pass path, byte-identical to pre-P2b.4.
  - `PRAutoBlogger_Tier_Router_Interface` (`providers/interface-tier-router.php`).
  - `PRAutoBlogger_Authority_Pipeline` (`core/class-authority-pipeline.php`, 247 lines): 6-stage orchestrator: research -> curate -> draft -> editorial -> seo -> publish gate. Cost-governor reserve/settle on every stage; `PRAutoBlogger_Cost_Ceiling_Exception` -> HOLD as draft (never force-complete). Citation gate: `citation_score >= threshold` to publish (default threshold 0.0, gate always passes until calibrated).
  - `PRAutoBlogger_Authority_Pipeline_Stages` (`core/class-authority-pipeline-stages.php`, 195 lines): stage helpers extracted for 300-line rule.
  - `PRAutoBlogger_Authority_Pipeline_Interface` (`providers/interface-authority-pipeline.php`).
  - **Article_Worker wired**: 3-line tier check before Economy path. Economy path unchanged.
  - **Imagery gate**: held articles get `_prautoblogger_imagery_suppressed = 1`; image pipeline skipped. Published articles proceed normally.
  - New options: `prautoblogger_authority_pipeline_enabled` (bool, default false), `prautoblogger_category_tiers` (serialized array, default empty = all Authority).
- PHPUnit: `TierRouterTest` (6 tests) + `AuthorityPipelineTest` (6 tests). All 544 tests GREEN on VPS PHP 8.3.

### Updated
- `ARCHITECTURE.md` — new files, options, post-meta, generation flow note (additions only).
- `CONVENTIONS.md` — tier routing + master flag pattern.
- `CONTEXT.md` — P2b.4 glossary section.
- `prautoblogger.php` — version bumped to 0.31.0.
- `uninstall.php` — new options covered by existing wildcard; `_prab_*` purge already present.

## [0.30.0] - 2026-06-24

### Fixed (QA P1/P2 sweep — post-a39fb75)
- **P1 (uninstall purge):** Added second `_prab_%` DELETE to `uninstall.php` §3. The existing `_prautoblogger_%` wildcard does not match the six `_prab_*` keys written by the SEO stage. All six keys (`_prab_schema_version`, `_prab_citations`, `_prab_about_peptides`, `_prab_review_mode`, `_prab_reviewed_at`, `_prab_citation_score`) are now purged on plugin deletion.
- **P2-2 (docblock clarity):** Updated `run()` side-effects note in `class-seo-stage.php` from "7 keys max" to "7 keys max — 6 written here; `_prab_reviewed_by` is P2b.4/human-approval only".
- **P2-3 (test coverage):** Added `test_peptide_ids_encoded_as_json_int_array()` to `SeoStageTest` — asserts that a non-empty `$peptide_ids` array is JSON-encoded as a sequential integer array in `_prab_about_peptides`. Total: 9 tests GREEN on VPS PHP 8.3.

### Added
- **P2b.3 — SEO stage: _prab_* meta writer + citation_score** — additive Authority-tier only; not wired into the live Economy (single-pass) path until P2b.4 (tier routing).
  - `PRAutoBlogger_Seo_Stage` (`core/class-seo-stage.php`, 173 lines) — deterministic (no LLM calls) meta-writer. Writes all keys from the ratified JSON-LD contract v1 (`convo/prcore/decisions/2026-06-11-jsonld-contract-v1.md`) to the published post so prcore can emit Drug/MedicalWebPage schema. Computes `citation_score` = average `quality_score` of kept sources (0.0–1.0). Records `run_stages` start→done (`agent_role=seo`) and a `run_decisions` row (`stage=seo, verdict=scored`). Reads `prautoblogger_citation_score_threshold` option (default `0.0`) and logs it — the publish gate acting on this score is P2b.4. Economy single-pass path untouched.
  - `PRAutoBlogger_Seo_Stage_Interface` (`providers/interface-seo-stage.php`) — contract for `run()`.
  - New option `prautoblogger_citation_score_threshold` (float, default 0.0, intentionally uncalibrated). Uninstall purges it with the existing `LIKE 'prautoblogger\_%'` wildcard.
  - _prab_* meta keys written: `_prab_schema_version` (int 1), `_prab_citations` (JSON kept sources), `_prab_about_peptides` (JSON peptide IDs), `_prab_review_mode` ('editorial-system'), `_prab_reviewed_at` (ISO 8601 datetime), `_prab_citation_score` (float as string). `_prab_reviewed_by` is NOT written by this stage (human approval only, P2b.4).
- PHPUnit: `SeoStageTest` — 8 tests covering schema_version=1, citations from kept sources, review_mode=editorial-system, citation_score computation (avg quality_score), score stored as post-meta, empty-sources score=0.0, threshold option read, run_stages start+done called. All 531 tests GREEN on VPS PHP 8.3.

### Updated
- `ARCHITECTURE.md` — SEO stage added to Phase 2b file tree, options table (`prautoblogger_citation_score_threshold`), and post-meta table (`_prab_*` keys).
- `CONVENTIONS.md` — Authority Pipeline SEO Stage section: design rules, key patterns, `_prab_*` key reference table.
- `CONTEXT.md` — New P2b.3 glossary section: SEO stage, JSON-LD contract v1, citation_score, citation_score_threshold, _prab_* key definitions.

## [0.29.0] - 2026-06-24

### Added
- **P2b.2 — bounded `Editorial_Loop`** — additive Authority-tier only; not wired into the live Economy (single-pass) path until P2b.4 (tier routing).
  - `PRAutoBlogger_Editorial_Loop` (`core/class-editorial-loop.php`, 300 lines) — iterative editor↔writer loop bounded by `prautoblogger_editorial_max_rounds` (new `get_option` key, default 3, configurable 1–10). Each round: Chief_Editor critiques → if 'approved' return content; if 'revised'/'rejected' get revised content → record round → repeat. After max rounds without approval, escalates to Review Queue (saves as draft, returns ''). Records every round to `run_decisions` (one row per round: verdict + "Round N: <notes>" rationale) and `run_stages` (start→done for role='editor' per round; start→done for role='writer' when writer revision is dispatched). Implements `PRAutoBlogger_Editorial_Loop_Interface`. `was_escalated()` returns true on exhaustion. `get_rounds()` returns the full `PRAutoBlogger_Editorial_Round[]` history.
  - `PRAutoBlogger_Editorial_Loop_Interface` (`providers/interface-editorial-loop.php`) — contract for `run()` + `was_escalated()`.
  - `PRAutoBlogger_Editorial_Revision_Caller` (`core/class-editorial-revision-caller.php`, 138 lines) — extracted writer-revision step (split from Editorial_Loop for 300-line compliance). Calls the writer LLM with `Content_Prompts::build_revision_system/user()`, logs cost via `Cost_Tracker`, manages run_stage start→done for role='writer'. Returns prior draft unchanged on empty LLM response (never silent pass).
  - `PRAutoBlogger_Editorial_Round` (`models/class-editorial-round.php`) — immutable value object capturing one loop iteration (round_number, editor_notes, editor_verdict, revised_content, quality_score, seo_score). `to_array()` for audit JSON snapshot.
  - `Content_Prompts::build_revision_system()` + `build_revision_user()` — two new static methods for revision LLM prompts; single-pass path untouched.
  - New option `prautoblogger_editorial_max_rounds` (int, default 3, range 1–10). Uninstall purges it with the existing `LIKE 'prautoblogger\_%'` wildcard.
- PHPUnit: `EditorialLoopTest` — 8 tests covering approval on round 1, escalation after max rounds, floor (min=1) clamp, cap (max=10) clamp, round recording to `run_decisions`, escalation recorded as single 'escalated' row, inline revision path (editor-provided `revised_content` skips writer call), writer revision path (Editorial_Revision_Caller invoked on 'rejected' verdict). All 523 tests GREEN on VPS PHP 8.3.

### Updated
- `ARCHITECTURE.md` — Editorial_Loop added to Phase 2b file tree, options table (`prautoblogger_editorial_max_rounds`), and pipeline data flow note.
- `CONVENTIONS.md` — Loop-bounded editorial pattern documented.
- `CONTEXT.md` — New P2b.2 glossary section: editorial loop, editorial_max_rounds, round record, Review Queue escalation.

## [0.28.0] - 2026-06-23

### Added
- **P2b.1 — Research_Fanout + Research_Judge (the `curate` stage)** — additive only; not wired into the live Economy (single-pass) path until P2b.4 (tier routing).
  - `PRAutoBlogger_Research_Fanout` — dispatches N specialist LLM research agents in parallel via `curl_multi` (default 3 agents, configurable 2–5 via `prautoblogger_research_agent_count`; MIN_AGENTS=2 because quorum=⌈N/2⌉+1=2 makes N=1 unsatisfiable). Reserves the SUMMED worst-case cost of the entire batch from the per-run cost governor before dispatch (atomic conditional write — concurrent writers cannot slip past the per-run ceiling). Quorum check: ⌈N/2⌉+1 agents must return usable results, else returns empty array (caller holds the run). Invalid/schema-mismatched agent results are excluded and never silently passed. Per-agent `run_stages` rows written with `researcher:<role>` agent_role. `settle()` wrapped in `try/finally` — always settles the reservation even if `batch->execute()` throws, preventing phantom reserved_usd in the run ledger.
  - `PRAutoBlogger_Research_Batch` — extracted curl_multi execution layer (mirrors `class-open-router-image-batch.php` pattern); keeps Research_Fanout under 300 lines and independently testable.
  - `PRAutoBlogger_Research_Judge` — the `curate` stage; deduplicates sources by URL-canonical match then semantic similarity (OpenRouter embeddings / cosine similarity, matching Semantic_Dedup), with keyword-overlap fallback. Assigns `quality_score` = relevance × source-type authority weight. Writes `run_sources` rows (kept=1 / kept=0 with reason + quality_score) via `Audit_Writer`. Returns top-12 scored sources for the draft stage.
  - `PRAutoBlogger_Research_Source_Scorer` — authority weighting helper (peer-reviewed→1.0, institutional→0.85, preprint→0.70, HTTPS→0.60, HTTP→0.40); extracted from judge to keep line count under 300.
  - Both subsystems behind interfaces (`PRAutoBlogger_Research_Fanout_Interface`, `PRAutoBlogger_Research_Judge_Interface`) for provider-swappability. Stage_Display_Map already contains `research` + `curate` from v0.18.0; no new stage vocabulary added.
- PHPUnit: `ResearchFanoutTest` (quorum logic, cost-reserve wiring, ceiling-breach abort, partial-failure) + `ResearchJudgeTest` (dedup, quality_score, run_sources writes, graceful absent-table degradation).
- `CONTEXT.md` Phase 2b P2b.1 section — glossary entries for fan-out, Research_Fanout, curate stage, Research_Judge, quorum, specialist role, quality_score, authority weight, MIN_AGENTS floor.

### Fixed (CI green — post-17cef8a testfix)
- **ResearchFanoutTest void log_api_call mock:** Removed willReturn(null) from make_cost_tracker(); log_api_call is declared void so PHPUnit raises IncompatibleReturnValueException. Fix: drop willReturn, keep bare method() call only.
- **ResearchFanoutTest Run_Context::set() undefined:** Changed PRAutoBlogger_Run_Context::set() to ::set_run_id() in test_ceiling_breach_exception_aborts_before_dispatch; the class API is set_run_id/current_run_id/clear only.
- **ResearchJudgeTest keyword-dedup collapses distinct sources to 1 (code regression):** deduplicate() keyword-overlap fallback lacked a minimum keyword count guard. Generic source titles (Source 0 / Excerpt 0) produce only 2 shared keywords, collapsing all sources to 1 unique. Fix: require count(kw) >= 3 before the overlap loop (mirrors Semantic_Dedup pattern); URL-exact dedup already handles same-URL duplicates; sparse keyword sets pass through unconditionally.

### Fixed (QA P1/P2 sweep — post-721bf8a)
- **P1-1:** Added Phase 2b P2b.1 glossary section to `CONTEXT.md` (DoD v1.2.0 §7 — new domain terms must be defined same PR).
- **P2-2:** Wrapped `batch->execute()` + results loop + `settle()` in `try/finally` so the cost-governor reservation is ALWAYS settled/released even if an unexpected exception is thrown, preventing phantom `reserved_usd` in the run ledger.
- **P2-1:** Added `test_ceiling_breach_exception_aborts_before_dispatch()` to `ResearchFanoutTest` — asserts that when `open_amount_reservation()` throws `PRAutoBlogger_Cost_Ceiling_Exception`, `batch->execute()` is never called (enforces cost-governance contract).
- **P2-3:** Raised `MIN_AGENTS` from 1 to 2 in `Research_Fanout` — with quorum=⌈N/2⌉+1, N=1 makes quorum=2 which can never be satisfied; floor of 2 ensures the minimum fan-out can actually succeed.

## [0.27.1] - 2026-06-23

### Fixed (M4 P2 cleanup)
- **P2-1 (safety cap):** Added `LIMIT 100` to the `generation_log` query inside
  `Gen_History_Query::get_run_io()` (`includes/admin/class-gen-history-query.php`).
  A run has ~6–10 stage rows in practice; 100 is a generous safety cap that prevents
  a pathological run with many log rows from blowing up the AJAX payload.
- **P2-2 (test):** Implemented the declared-but-missing
  `test_get_page_returns_empty_when_wpdb_null()` test in
  `tests/unit/Admin/GenHistoryQueryTest.php`. Confirms the `get_page()` null-wpdb
  guard returns `array( 'rows' => array(), 'total' => 0 )`.

## [0.27.0] - 2026-06-23

### Fixed (QA P1/P2 sweep — post-3cd2843)
- **P1-C (regression):** Restored `get_option( 'prautoblogger_board_column_limit', PRAUTOBLOGGER_DEFAULT_BOARD_COLUMN_LIMIT )`
  in both `get_in_review_cards()` and `get_published_cards()` — the M5 commit had hard-coded
  `posts_per_page => 20`, silently bypassing the board column-limit admin setting.
- **P1-D (test):** Removed invalid `Functions\when( 'PRAutoBlogger_Run_Stage_State::is_available' )` stub
  from `BoardInspectorHandlerTest.php` line 99. `Brain\Monkey\Functions\when()` only intercepts
  global functions; the static-method stub was a silent no-op violating the repo rule against
  `Functions\when()` on `Class::method`.
- **P1-A (docs):** Added `class-board-stage-dots.php` (admin/) and `class-board-inspector-handler.php`
  (ajax/) to the ARCHITECTURE.md file tree.
- **P1-B (docs):** Added Mission Brief domain vocabulary (Mission Brief, inspector rail, dot-rail,
  run_stages_summary) to CONTEXT.md.
- **P2-A:** Deleted dead `assets/js/board-generate.js` (no longer enqueued; button gone in M5).
- **P2-B:** Added `return;` after `wp_send_json_error()` in
  `class-board-inspector-handler.php` handle() for explicit early-exit consistency.

### Added
- **Pipeline Board M5 -- Mission Brief (board redesign, CEO-selected Direction C):**

  - **Vertical run list:** replaces the four-column kanban with a status-grouped vertical
    list (Generating | In review | Published | Failed). Each row shows a verdict-style
    status chip, article title, current stage, cost (IBM Plex Mono), and elapsed time.
    Stalled/failed rows have a red left-border accent and are never softened to grey.

  - **Persistent right-rail inspector:** selecting any run row fetches full per-stage
    I/O via new `PRAutoBlogger_Board_Inspector_Handler` (AJAX) and populates the rail
    without navigation. Inspector shows: stage breakdown with status dots, model,
    per-stage cost, expandable prompt/response text (textContent -- XSS-safe), total
    cost receipt, and an "Open dossier" CTA.

  - **Reuses M4 data layer:** `PRAutoBlogger_Gen_History_Query::get_run_io()` and
    `get_run_meta()` power the inspector -- no new DB queries or schema changes.

  - **Stage-display-map driven:** stage labels resolve via `Stage_Display_Map::label()`;
    Phase 2b stages (curate/seo) appear automatically.

  - **New Article action** preserved (top-right of board heading, links to Ideas Browser).

  - All existing board capabilities preserved: per-run dossier deep-link, status counts,
    live AJAX polling with backoff, published-window filter, poll-interval setting,
    human-modified badge, error banner on poll failure, empty state per section.

  - **Security:** inspector AJAX uses `prautoblogger_board` nonce (same page as poller),
    `manage_options` cap; all output `esc_html`'d before JSON; JS renders prompt/response
    via `textContent`, never `innerHTML`. API key never exposed (architecture contract).

## [0.26.0] - 2026-06-23

### Added
- **Pipeline Settings M4 — Generation History: run list + per-step I/O drill-down (CEO ask #5 complete):**

  - **Generations list (`prautoblogger-gen-history`):** A browsable, paginated admin page (20 runs
    per page, newest first). Each row shows the linked article title (when the run produced a post),
    start date/time, final run status chip, distinct model(s) used, settled cost, and duration.
    Linked runs surface a "Dossier" button deep-linking to the existing Article Dossier; all runs
    (including failed/orphan ones) have a "Stage I/O" toggle for the inline drill-down. Queries
    are bounded (LIMIT/OFFSET) — no unbounded SELECT *.

  - **Per-step input AND output drill-down:** "Stage I/O" opens an inline panel (loaded via AJAX
    on first click, then toggled). For each generation_log row the panel shows:
    - **Input — System Prompt:** the `system`-role message content extracted from
      `generation_log.request_json` (the prompt template the LLM received after rendering).
    - **Input — Assembled Instruction:** the `user`-role message content (the fully token-filled
      instruction the LLM received).
    - **Output — Model Response:** extracted from `run_stages.meta_json.output` (the LLM's raw
      text response). When pruned by the retention policy or absent for log-only stages (image_a/
      image_b, llm_research, etc.), the panel says so explicitly rather than leaving a blank.
    - Per-stage model, token counts, estimated cost, and response status.

  - **Stage-list-driven:** Stage labels come from `Stage_Display_Map::label()` so Phase 2b
    `curate`/`seo` stages appear automatically without rework.

  - **Dossier as primary per-step I/O surface:** For runs that produced a post the "Dossier"
    button is the recommended entry point — the dossier already renders every stage's input and
    output (request payload + stage snapshot) with the full editorial context. The inline Stage I/O
    panel is complementary; it works for orphan/failed runs and shows the same data in a lighter UI.

  - **gen-log INPUT/OUTPUT coverage confirmed:**
    - INPUT: `generation_log.request_json` stores the full JSON chat body (messages[], model,
      temperature, …) assembled by `build_body()` before the `Authorization` header is added.
      The `Request_Recorder` captures only the body — auth headers are never stored. System and
      user message content are extracted and surfaced.
    - OUTPUT: `run_stages.meta_json` holds `{output: "..."}` — the LLM's raw response text
      written by `Run_Stage_State::done()`. Image/log-only stages (image_a, image_b,
      llm_research, image_prompt_rewrite) have no `run_stages` row; their output is null (not
      pruned). When `meta_json` is present but lacks the `output` key (pruned by the
      `prautoblogger_request_json_retention_days` setting), `output_pruned = true` is returned
      and the UI explains the gap honestly.

  - **New files:** `includes/admin/class-gen-history-query.php`,
    `includes/admin/class-gen-history-page.php`,
    `includes/ajax/class-gen-run-io-handler.php`,
    `templates/admin/gen-history-page.php`,
    `assets/css/gen-history.css`,
    `assets/js/gen-history.js`.

### Fixed (M3 P2 sweep)
- **Removed dead `data-preview-nonce` and `data-diff-nonce` HTML attributes** from
  `templates/admin/pipeline-settings-prompt-panel.php`. The JS has always read nonces from
  `prabPipeline.previewNonce` / `prabPipeline.diffNonce` (via `wp_localize_script` in
  `Pipeline_Settings_Page::on_enqueue_assets`), not from `data-*` attrs — confirmed by grep.
  Removing them eliminates redundant server-side `wp_create_nonce()` calls in the renderer.
- **Removed 3 redundant `wp_create_nonce()` calls** from
  `includes/admin/class-pipeline-settings-renderer.php` (`preview_nonce`, `history_nonce`,
  `diff_nonce` keys) — they were only feeding the now-removed dead `data-*` attrs.

## [0.25.0] - 2026-06-23

### Added
- **Pipeline Settings M3 — assembled-instructions preview + prompt version history/diff:**
  Per-prompt Template/Preview toggle and version history accordion in the Pipeline Settings page.

  - **Template / Preview toggle (per prompt):** A segmented button pair sits above every
    prompt editor. "Template" (default) shows the editable textarea with the note "Editing the
    template affects all future runs." "Preview assembled instructions" (read-only) fetches and
    shows the fully-rendered instruction text the LLM actually received, with all tokens filled.
    The two states are mutually exclusive; the preview pane has no save path server-side or
    client-side — CPO guardrail enforced at both layers.

  - **Preview source (last-run preferred, sample fallback):** `PRAutoBlogger_Pipeline_Preview_Source`
    queries `prab_generation_log` for the most recent successful row whose stage maps to the
    prompt key via `Stage_Display_Map::all()` (stage-list-driven — Phase 2b stages resolve
    automatically). Extracts the rendered message content from `request_json` (system-role
    content for `*.system` keys; user-role content for agent keys). When no run exists yet,
    renders the active template with `[token_name]` placeholders so the admin sees the token
    injection map before any article has been generated. Clearly labelled in the UI
    ("Tokens from last run · <date>" vs. "Sample render — no run found yet").

  - **Version history accordion (per prompt):** Collapsible list below the editor showing
    every stored version (version number, author, created_at, active badge). Read-only;
    never mutates the registry. The "Diff" button computes and shows an inline line-level diff
    between adjacent versions (v(N-1) → vN) using a pure-PHP LCS implementation with a
    3-line context window and `omitted: N lines unchanged` collapse for distant context.
    Diff text is rendered by type (added/removed/context/omitted) with colour coding matching
    the designer mockup. All diff output is `esc_html`'d server-side and inserted via
    `textContent` in JS (double-escaped path).

  - **New AJAX endpoints (both `manage_options` + nonce gated):**
    - `prautoblogger_pipeline_preview` — returns rendered preview text (from last run or
      sample); no save path; server-side read-only contract.
    - `prautoblogger_pipeline_history` — returns version list for a key.
    - `prautoblogger_pipeline_diff` — returns LCS diff between two versions.
    All endpoints validate the prompt key against `Step_Map::allowed_prompt_keys()` (the
    same allowlist the save handler uses) before any DB access.

  - **Stage-list-driven throughout:** all preview/history/diff logic goes through
    `Stage_Display_Map::all()` and `Step_Map::allowed_prompt_keys()`; no hard-coded stage
    names — Phase 2b `curate`/`seo` stages will work without rework.

### New Files
- `includes/ajax/class-pipeline-preview-handler.php` — Preview AJAX endpoint.
- `includes/ajax/class-pipeline-history-handler.php` — History + diff AJAX endpoint.
- `includes/admin/class-pipeline-preview-source.php` — Last-run / sample render logic.
- `tests/unit/Ajax/PipelineHistoryHandlerTest.php` — Tests for diff algorithm + slug resolution.
- `tests/unit/Ajax/PipelinePreviewSourceTest.php` — Tests for message extraction + stage mapping.

### Changed
- `includes/admin/class-pipeline-settings-renderer.php` — M3: passes `preview_nonce`,
  `history_nonce`, `diff_nonce`, action names, and `panel.versions` list to the template;
  `build_prompt_panel_data()` now includes the full `versions` array for server-side
  history rendering.
- `includes/admin/class-pipeline-settings-page.php` — M3: localizes `prabPipeline` JS
  object with AJAX actions + nonces; no menu changes.
- `templates/admin/pipeline-settings-prompt-panel.php` — Redesigned: added Template/Preview
  toggle (`pab-tp-toggle`), preview block with read-only badge + source note,
  version history accordion with Diff buttons, and inline diff panel.
- `templates/admin/pipeline-settings-page.php` — Passes full `$view` to the prompt panel
  partial (required for nonce data-attributes).
- `assets/js/pipeline-settings.js` — M3: toggle logic, preview AJAX fetch, history
  accordion expand/collapse, diff AJAX fetch + render, diff close.
- `assets/css/pipeline-settings.css` — M3: styles for tp-toggle, preview block,
  history accordion, diff panel (appended; all existing styles preserved).
- `includes/class-prautoblogger.php` — Registers `Pipeline_Preview_Handler` and
  `Pipeline_History_Handler` hooks in `register_ajax_hooks()`.

### Tests
- `PipelineHistoryHandlerTest`: covers `compute_diff()` (change detection, identical texts,
  line shape, omitted-context collapse) and `resolve_key_from_slug()` round-trip.
  Brace count: 12/12.
- `PipelinePreviewSourceTest`: covers `extract_rendered_from_messages()` (system vs user role
  preference, longest fallback, empty input) and `stage_for_key()` (known + unknown keys).
  Brace count: 8/8. All methods use reflection on private statics — no `Functions\when()`
  on `Class::method` (DoD invariant preserved).

### Fixed
- `PipelinePreviewSourceTest::test_stage_for_key_maps_research_system`: corrected expected
  value from `'research'` to `'llm_research'`. The production `stage_for_key('research.system')`
  returns `'llm_research'` because `Stage_Display_Map::MAP` lists `llm_research` before the
  Phase 2b `research` entry (both share `prompt_key => 'research.system'`; first match wins).
  The real `prab_generation_log.stage` value written by `LLM_Research_Provider::collect_data()`
  is `'llm_research'` — confirmed in `includes/providers/class-llm-research-provider.php` line 74.
  The mapping was correct; the test assertion was wrong.


## [0.24.0] - 2026-06-23

### Added
- **Pipeline Settings M2 — step option fields:** All per-step content settings are now
  editable directly in the Pipeline Settings page.
  - **Global Content Context** block at top of Pipeline page: editable `prautoblogger_niche_description` form (replaces the read-only preview from M1).
  - **Research step:** `enabled_sources`, `target_subreddits`, `reddit_time_filter`, `reddit_posts_per_subreddit`, `pullpush_cache_ttl`, `research_prompt` moved here from Sources tab.
  - **Analysis step:** `analysis_instructions`, `topic_exclusions` moved here from Content tab.
  - **Writer step:** `writing_pipeline`, `tone`, `min_word_count`, `max_word_count`, `writing_instructions`, `reasoning_enabled`, `reasoning_effort` moved here from Content/AI Models tabs.
  - **Editorial step:** `editor_instructions` moved here from Content tab.
  - New `pipeline_action = save_step_settings` POST handler with `step_context` routing; validates context, iterates field definitions, sanitizes per field type, and persists via `update_option()`.
  - New `PRAutoBlogger_Pipeline_Settings_Option_Fields` (public API: `contexts()`, `allowed_options()`, `get_fields_for_context()`, `sanitize_option()`) and companion data class `PRAutoBlogger_Pipeline_Settings_Option_Fields_Data`.
  - New `pipeline-settings-step-options.php` partial template renders type-aware field controls (textarea, select, number, toggle, checkboxes).

### Changed / Retired
- **Settings tabs `prautoblogger_models`, `prautoblogger_content`, `prautoblogger_sources` retired.**
  Removed from `PRAutoBlogger_Settings_Fields::get_sections()` and all field definitions removed from `get_core_fields()` / `PRAutoBlogger_Settings_Fields_Extended::get_fields()`. The wp_option keys are unchanged; sanitization now happens in the Pipeline page save handler. `uninstall.php` wildcard purge is unaffected.
- `PRAutoBlogger_Pipeline_Settings_Renderer::render()` now passes `global_fields`, `step_context`, and `step_fields` to the template.

### New Files
- `includes/admin/class-pipeline-settings-option-fields.php`
- `includes/admin/class-pipeline-settings-option-fields-data.php`
- `templates/admin/pipeline-settings-step-options.php`


### Tests
- **PHPUnit (P1-1 QA fix):** Added `test_research_context_saves_source_settings()` and
  `test_research_context_checkboxes_filters_unknown_sources()` to
  `tests/unit/Admin/PipelineSettingsStepSaveTest.php`. Covers the research context
  integration path end-to-end: `step_context=research` → fields fetched → POST read
  → `sanitize_option()` (checkboxes type) → `update_option()` persisted. First test
  asserts `status=saved` and that `prautoblogger_enabled_sources` decodes to `["reddit"]`;
  also asserts `prautoblogger_reddit_time_filter` and that the posts-per-subreddit
  value is an integer. Second test asserts that unknown source keys are stripped
  by the choices allowlist while valid keys (`reddit`, `llm_research`) survive.
- **Parse-error fix (P1-2 QA fix):** Moved the two research test methods inside
  the `PipelineSettingsStepSaveTest` class body; a premature class-closing brace
  after `test_missing_nonce_field_returns_idle()` caused a PHP parse error
  (CI red on all 3 PHP versions). Brace count corrected: 21 `{` = 21 `}`.

## [0.23.0] - 2026-06-22

### Added
- **Pipeline Settings (M1 — additive):** New "Pipeline" wp-admin submenu page
  (`prautoblogger-pipeline`) with a step rail covering all LLM stages: Research,
  Analysis, Writer, Editorial, Image. Per-step panels expose the existing model
  picker (`PRAutoBlogger_OpenRouter_Model_Field`), system-instruction editor, and
  agent-prompt editor(s) — all bound to the live versioned prompt registry
  (`PRAutoBlogger_Prompt_Registry`). Saving a prompt creates a new immutable version;
  reset-to-default available. Active version shown per panel. Writer panel marks
  inactive sub-stage prompts. No existing Settings sections changed in M1 (both
  surfaces edit the same options / prompts table).
- **`get_stages_for_setting()` extended (text→text only):** `prautoblogger_research_model →
  [research, llm_research]` added for stage-accurate cost-preview. Image model
  cost preview intentionally omitted (shows static $/image price in picker trigger
  instead — token-based formula does not apply).
- New files: `includes/admin/class-pipeline-settings-page.php`,
  `class-pipeline-settings-renderer.php`, `class-pipeline-settings-save-handler.php`,
  `class-pipeline-settings-step-map.php`; `assets/css/pipeline-settings.css`;
  `assets/js/pipeline-settings.js`; `templates/admin/pipeline-settings-page.php`;
  `CONTEXT.md` (domain glossary, DoD v1.2.0 §7).

### Fixed (QA REQUEST-CHANGES — commit e6550e1)
- **Model save POST key (P1-1):** `handle_model_save()` now reads the model value
  from `$_POST[$option_name]` — the key that `PRAutoBlogger_OpenRouter_Model_Field::render()`
  emits as `<input name="$option_name">` and that `model-picker.js` writes to by DOM id.
  The old code read `$_POST['model_id']`, which was never present in the POST body,
  silently overwriting the stored model with an empty string on every "Save Model" click.
  Removed the dead `prautoblogger:model_selected` event listener in `pipeline-settings.js`
  (the event was never fired by `model-picker.js`).
- **Renderer: no business logic in template (P2-3):** `PRAutoBlogger_Cost_Reporter`
  instantiation moved from `pipeline-settings-page.php` template into
  `Renderer::render()`; template now receives `$view['monthly_spend']` and
  `$view['budget']` as plain scalars.
- **Renderer: dead DB query removed (P2-2):** `$all_versions` assignment in
  `build_step_data()` removed (it triggered a `SELECT * FROM wp_prautoblogger_prompts`
  query on every page load but the result was never used).
- **Image cost-preview dead config resolved (P2-1):** Removed unreachable
  `prautoblogger_image_model → [image_a, image_b]` map entry from
  `get_stages_for_setting()` and added a comment explaining the intentional design.

### Docs
- **CONVENTIONS.md (P1-2):** Added `## How To: Add a New Pipeline-Style Admin Page`
  section documenting the four-class split, registration priority, renderer contract,
  POST key convention for the model picker, nonce/cap pattern, allowlist enforcement,
  prompt key slug round-trip, and test requirements.
- **CONTEXT.md (P2-4):** Created root domain glossary covering all new Pipeline
  Settings terms (step, step rail, step panel, pipeline-ui, system instruction,
  agent prompt, system_key, agent_key, prompt key, prompt slug, reset-to-default,
  step map, save handler) and prior prompt-registry terms.

### Tests
- **PHPUnit (P1-3):** Added `tests/unit/Admin/PipelineSettingsSaveHandlerTest.php`
  covering allowlist enforcement (unknown option/slug rejected), correct POST key
  reading (`$_POST[$option_name]` not `$_POST['model_id']`), capability gate,
  nonce gate, and GET idle return.
- **PHPUnit (P1-3):** Added `tests/unit/Admin/PipelineSettingsStepMapTest.php`
  covering `allowed_prompt_keys()` / `allowed_model_options()` regression guards,
  step field completeness, unique step ids, slug round-trip for simple and
  underscore-containing keys, and `find()` behaviour.
- **PHPUnit fix:** Removed invalid `Brain\Monkey\Functions\when()` stub for
  `PRAutoBlogger_Prompt_Registry_Writer::create_version` in
  `PipelineSettingsSaveHandlerTest::test_handle_prompt_save_rejects_unknown_slug`.
  Brain\Monkey's `Functions\when()` stubs only global PHP functions, not static
  class methods; the stub was also unnecessary because the allowlist rejection
  returns before `create_version()` is ever reached.
- **CONVENTIONS.md fix:** Corrected slug example for keys containing underscores:
  `content.single_pass` → `content-single_pass` (not `content-single-pass`);
  `sanitize_key()` preserves underscores so the round-trip is unambiguous.

## [Unreleased - Internal]

### Internal
- ADR log scaffolded: `docs/adr/` created as the app-local decision log for this plugin; `docs/adr/README.md` is the index; ADR-0001 documents the v0.16.0 editorial illustration image-style pivot. `ARCHITECTURE.md` updated to point to the folder. No runtime changes; docs-only.
- CI constants guard (`scripts/check-constants.php`): new required CI step (`Check PRAUTOBLOGGER_* constants`) fails the build when any `PRAUTOBLOGGER_*` constant referenced in shipped plugin PHP is neither defined in `prautoblogger.php` nor `defined()`-guarded at the reference site. Prevents a recurrence of the v0.19.0 admin-500 regression class.

## [0.22.1] - 2026-06-22

### Added
- **`wp prautoblogger generate --sync`** (VPS orchestrator mode): runs the full
  checkpoint pipeline synchronously in the calling process — acquires lock, calls
  `on_orchestrate_tick()` in-process, then loops `on_generate_tick()` until the
  queue is empty or a terminal state is reached (max 20 ticks). Exits 0 on
  success with a JSON summary line; exits 1 on error. Designed to be called from
  the Coolify VPS over a held-open SSH session, where Hostinger's ~10-min
  background-process kill does not apply.

### Fixed
- **`$sync_mode` guard in `Generation_Checkpoint_Runner::on_generate_tick()`**:
  when running under `--sync`, the internal `wp_schedule_single_event()` +
  `fire_cron_now()` reschedule is suppressed so the VPS's external loop cannot
  race with a competing background wp-cron tick. Existing async (cron/AJAX) paths
  are unaffected.
- **Reaper timezone fix** in `Run_Reaper::sweep_stuck_stages()` and
  `sweep_stuck_runs()`: `$cutoff` was computed with `gmdate()` (UTC) while
  `updated_at` is stored via `current_time('mysql')` (server local time, UTC+8).
  Changed to `wp_date()` so the cutoff respects the site timezone. On this host
  the 8-hour delta could suppress reaping of a same-day-stalled run; the 44h-old
  run `ee6e7d91` was already far outside the threshold and unaffected.
- **Scoped self-heal migration** in `DB_Migrations`: restores the low-stakes
  maintenance cron events (`prautoblogger_reap_orphan_research_rows`,
  `prautoblogger_collect_metrics`, `prautoblogger_sync_runware_models`) that
  vanished after the 06-15 deploy cycle. **`prautoblogger_daily_generation` is
  deliberately NOT re-registered** — the VPS systemd timer owns generation
  scheduling as of v0.22.1. Note: wp-cron reliability on this Hostinger host is
  suspect (background PHP killed ~10 min); maintenance crons are low-stakes and
  acceptable on this transport; generation is not.
- **Sync-mode guard tests** (QA NF-1 remediation 2026-06-23): moved sync-mode
  tests to `GenerationCheckpointRunnerSyncModeTest.php`; fixed
  `test_orchestrate_tick_in_sync_mode_does_not_schedule_background_event` to
  return >= 1 idea from `orchestrate_only()` so execution reaches the sync guard
  at line ~181 (was vacuous -- previous stub returned `[]` causing early return);
  added `test_generate_tick_sync_mode_suppresses_reschedule` for the
  `on_generate_tick()` guard; async regression test preserved in same file.
- **Root `CONTEXT.md`**: created domain glossary (run/run_stages/checkpoint/
  idea-queue/Generation_Lock) and execution mode comparison (async wp-cron vs.
  `--sync` VPS).
- **`ARCHITECTURE.md` SS25**: added `WP-CLI (sync / VPS)` row to Entry Points table.

## [0.22.0] - 2026-06-16

### Changed
- **CI adopted estate standard (reusable workflow)**: `ci.yml` is now a thin
  caller of `peptiderepo/peptide-e2e/.github/workflows/ci.yml@main` with
  `tests: brain-monkey`. Removes duplicate per-repo lint, PHPCS, and PHPUnit
  jobs (now provided by the reusable workflow). The phpcbf auto-fix commit-back
  pattern (prautoblogger gold standard) is now the reusable implementation.
- **Deploy gate upgraded**: `deploy.yml` no longer runs a standalone PHP-lint
  validate job; it calls `./.github/workflows/ci.yml` (which includes the full
  reusable suite) via `workflow_call`. This closes the gap where deploy's lint
  gate was weaker than CI's PHPCS gate.
- **Constants guard retained**: `check-constants` job kept as a second parallel
  job in `ci.yml` alongside the reusable `ci` job. No regression from the
  v0.19.0 protection.
- **`composer.json` test script added**: `composer test` now runs
  `vendor/bin/phpunit --testdox` (required by the reusable workflow's phpunit job).

### Refactored (300-line rule compliance)
- `PRAutoBlogger_Keyword_Extractor` (new, `core/class-keyword-extractor.php`):
  shared keyword tokeniser extracted from `Idea_Scorer` and `Semantic_Dedup`;
  both now delegate to `PRAutoBlogger_Keyword_Extractor::extract()`.
- `PRAutoBlogger_Image_Aspect_Ratio` (new, `providers/class-image-aspect-ratio.php`):
  aspect-ratio snap utility extracted from `OpenRouter_Image_Provider` and
  `OpenRouter_Image_Batch`; both now call `PRAutoBlogger_Image_Aspect_Ratio::snap()`.
- `PRAutoBlogger_Settings_Sanitizer` (new, `admin/class-settings-sanitizer.php`):
  all option sanitization logic extracted from `Admin_Page::sanitize_field()`;
  `Admin_Page` delegates via `PRAutoBlogger_Settings_Sanitizer::sanitize_field()`.
- `PRAutoBlogger_Settings_Fields_Images` (new, `admin/class-settings-fields-images.php`):
  image-section field definitions extracted from `Settings_Fields_Extended`;
  `Settings_Fields_Extended::get_fields()` merges via array union.
- `PRAutoBlogger_Runware_Validator` (new, `providers/class-runware-image-validator.php`):
  `validate_credentials_detailed()` extracted from `Runware_Image_Provider`;
  provider delegates via `PRAutoBlogger_Runware_Validator::validate()`.
- `class-runware-image-batch.php`: 15 individual `phpcs:disable` lines collapsed
  to a single grouped suppression (equivalent effect, saves 14 lines).
- `class-open-router-image-provider.php`: `snap_aspect_ratio` private method and
  `STANDARD_ASPECTS` constant removed (now handled by `Image_Aspect_Ratio`).

## [0.21.0] - 2026-06-14

### Added
- **Board âNew Articleâ button (Phase 2 admin M4)**. Manual generation is now
  accessible from the kanban board (not just the Settings page). The button fires
  the existing `prautoblogger_generate_now` AJAX action; the board status area
  shows generation progress via the existing transient poller. Requires
  `manage_options` capability.
- **Chained-cron checkpoint machine** (`PRAutoBlogger_Generation_Checkpoint_Runner`).
  Replaces the monolithic `on_manual_generation()` synchronous path with a
  two-tick checkpoint machine so Hostingerâs PHP-process kill cannot abort a
  mid-pipeline run:
  - Tickâ1 (`prautoblogger_gen_orchestrate`): collect â analyze â score ideas,
    persist to `prautoblogger_article_queue` option, schedule Tick 2.
  - Tickâ2â¦N (`prautoblogger_gen_tick`): pop ONE idea, generate one article,
    reschedule or finalize. Persists consumed queue BEFORE generating to prevent
    orphan-recovery duplication on restart.
  - Budget / halt: `Cost_Tracker::is_budget_exceeded()` checked per tick;
    a halted run aborts all remaining ticks without calling `Article_Worker`.
- **WP-CLI `wp prautoblogger generate` command**. Routes through the same
  chained-cron checkpoints as the board button. Replaces the retired SSH-only
  `wp eval 'do_action("prautoblogger_manual_generation")'` workaround.
- **Settings-backed board pagination** (perf pass). All hardcoded LIMIT / `posts_per_page`
  values in board queries replaced with two new settings:
  - `prautoblogger_board_column_limit` (default 20, constant `PRAUTOBLOGGER_DEFAULT_BOARD_COLUMN_LIMIT`)
  - `prautoblogger_ideas_per_page` (default 30, constant `PRAUTOBLOGGER_DEFAULT_IDEAS_PER_PAGE`)
- **DB indexes** (via `PRAUTOBLOGGER_DB_VERSION` â `1.4.0`):
  - `generation_log`: `KEY run_id_post_id`, `KEY created_status` (board query + failed-cards).
  - `analysis_results`: `KEY analyzed_type` (ideas-browser pagination + type filter).
  - `runs`: `KEY started_at` (articles-list pagination `ORDER BY started_at DESC LIMIT n OFFSET m`).
- **Query helper split**: `PRAutoBlogger_Ideas_Browser_Query` (new `class-ideas-browser-query.php`)
  carries static query helpers extracted from `class-ideas-browser.php` to enforce the
  300-line rule (CONVENTIONS Â§1).
- **Guard P2s** (`scripts/check-constants.php`):
  - Added `--self-test` mode: synthesizes a missing constant and asserts the scanner catches it.
  - Added inline comment documenting `CONSTANTS_GUARD_WINDOW = 4`.
  - Wired `--self-test` as a CI step after the main constants check.
- **PHPUnit test** `tests/unit/Core/GenerationCheckpointRunnerTest.php`: tick-by-tick
  state-machine proof (kick_off schedules only, empty queue finalizes, halted run aborts,
  multi-idea queue pops one and reschedules).
- **Pipeline_Status** now exports `write_initial()` and `write_error()` for the
  checkpoint runner to set the status transient before the first cron tick fires.

### Changed
- `Executor::on_manual_generation()` now delegates to
  `Generation_Checkpoint_Runner::on_orchestrate_tick()` instead of running the full
  pipeline synchronously. The method body is 1 line; the synchronous path is retired.
- `Pipeline_Runner::orchestrate_only()` added as a public wrapper exposing the private
  `orchestrate()` method for use by `Generation_Checkpoint_Runner`.

### Removed
- **SSH-only workaround retired**: `wp eval 'do_action("prautoblogger_manual_generation")'` no
  longer fires the pipeline synchronously in one PHP process. Use `wp prautoblogger generate`
  instead (routes through the chained-cron checkpoint machine). See ARCHITECTURE.md Â§25.

## [0.20.1] - 2026-06-12

### Fixed
- **Restore three bootstrap constants deleted by the v0.19.0 constants-block regression**
  (`PRAUTOBLOGGER_DEFAULT_RUN_CEILING_USD` 0.50, `PRAUTOBLOGGER_DEFAULT_REQUEST_JSON_RETENTION_DAYS` 14,
  `PRAUTOBLOGGER_DEFAULT_REASONING_MAX_TOKENS` 2048 — verbatim v0.18.3 values). PR #156 (v0.19.0)
  dropped them from `prautoblogger.php`; the gap was latent because `tests/bootstrap.php` defines
  its own fallbacks (CI green) and no exercised v0.19.x runtime path read them (prod reasoning
  disabled). v0.20.0's activator defaults + run-state ceiling read them on the admin
  migration path -> fatal 500 on all plugin admin pages immediately after the v0.20.0 deploy
  (2026-06-12; front-of-site unaffected; hot-patched on prod within minutes, this commit makes
  it canonical). Follow-up owed: CI guard asserting every referenced PRAUTOBLOGGER_* constant
  is production-defined or defined()-guarded (maintenance thread).

## [0.20.0] - 2026-06-12

### Added
- **Edit + single-step re-run (Phase 2 admin M3)** under the five CPO guardrails
  (cpo seq 8, hard AC). From the Article Dossier, eligible stages can have their
  input edited and re-run as QUEUED background jobs (chained-cron — the UI never
  implies synchronous execution; board + dossier polling shows queued → pickup →
  result).
  - **Immutable input versions (guardrail 1).** Edits fork into the new INSERT-only
    `prautoblogger_stage_inputs` table (monotonic versions per stage scope); the
    executed original in `generation_log.request_json` is never overwritten. The
    editor locks message structure (count/roles) so every fork stays replayable.
  - **Visible human-modified audit (guardrail 2).** `run_stages.human_modified` +
    `run_decisions.human_modified` set when an edited fork enters execution (sticky,
    never cleared); surfaced as dossier header + stage chips, a board-card chip, and
    decisions recorded while rebuilding a human-modified item are flagged too.
  - **Explicit downstream invalidation (guardrail 3).** Re-running a stage marks all
    downstream chain stages `stale` (new column — rows STAY 'done', so the resume
    machinery can never silently auto-re-run them). Stale chips render per stage, in
    the dossier header, and as a Review Queue warning chip linking to the dossier
    (stale state visible at publish). Downstream re-runs are deliberate: per-stage
    "Re-run from here" rebuilds the target + downstream from current upstream
    snapshots via the existing worker resume path.
  - **Cost governor applies to re-runs (guardrail 4).** Replays go through the normal
    provider seam: reserve-before-call against the SAME run ledger row.
    `Run_State::reopen()` re-snapshots the ceiling from the current setting and never
    resets accumulated spend. The dossier shows a Run Spend card + per-panel spend
    strip and warns when committed spend reaches the ceiling fraction (filterable,
    `prautoblogger_rerun_ceiling_warn_fraction`, default 0.8).
  - **Published posts are frozen (guardrail 5).** publish/future/private lock the run
    server-side (not just hidden UI); `Publisher` refreshes re-run output onto
    UNPUBLISHED posts only, preserving their status — a re-run never publishes.
- **B1 — `request_json` persisted on every chat LLM call** (QA M2 F1). The provider
  records the outgoing request BODY pre-dispatch (process-scoped recorder, consume-once);
  `log_api_call()` writes it to the row. Authorization headers can never be recorded
  (built separately; body keys whitelisted — covered by tests). Retention rides the
  existing `prautoblogger_request_json_retention_days` prune; the dossier raw-trace
  "Request payload" section now renders for new runs.
- **Idea seeds.** `Article_Worker` persists the exact `Article_Idea` payload once per
  item (stage_inputs, source='seed') so re-run-from-here reconstructs the precise idea
  (re-deriving from post fields could break the item-key hash and duplicate a post).
- **Dossier sidebar consolidation (QA M2 F2).** New "Models & Prompts" card: per-stage
  model + prompt version + agent role at a glance, plus the "Run Spend" card.
- **Image sections wired to real data (QA M2 F3).** image_a/image_b (and every
  log-only stage: llm_research, image_prompt_rewrite, …) now render from their actual
  generation_log rows, with the post's pipeline attachments (featured/og/square via
  `_prautoblogger_image_role`) shown as an image grid — chosen/featured highlighted.
- **Schema (db 1.3.0).** `run_stages` + `human_modified`/`stale`; `run_decisions` +
  `human_modified`; new `stage_inputs` table. dbDelta-idempotent via the standard
  self-healing migration path (admin_init + cron init); half-migrated sites degrade
  gracefully (done() retries the legacy statement; M3 features no-op until migrated).
  Uninstall drops the new table and clears the two new cron hooks.

### Changed
- **`PRAutoBlogger_Run_Stage_State` split** (300-line cap, M2 pattern): pipeline write
  bodies moved verbatim to `Run_Stage_Writes` (public API unchanged via thin proxies);
  operator-action mutations (restart / mark_stale / demote_to_pending) isolated in
  `Run_Stage_Rerun_State` — the ONLY code allowed to demote a done row. `done()` now
  clears `stale` on fresh output.
- **Dossier is item-scoped** (M2 correctness fix): stage rows filter to THIS post's
  item (idea-hash meta), and gen_log rows linked to OTHER posts of a multi-article run
  are excluded (unlinked run-level rows are kept).
- **`Publisher` idempotency short-circuit** now refreshes existing UNPUBLISHED posts
  with re-run content (title/content/verdict meta; status untouched) instead of
  silently discarding regenerated output. Published posts remain untouched.
- Review Queue rows show a stale-stages warning chip linking to the dossier; board
  cards show a "Human-modified" chip (single batched query, no N+1).

### Fixed
- Dossier "Request payload" raw trace was always empty (request_json never populated —
  QA M2 F1); image sections always rendered the absent state (QA M2 F3).
## [0.19.4] - 2026-06-12

### Added
- **GD-rung unit tests.** New `ImageComposerEditorTest.php` (7 tests) covering
  `PRAutoBlogger_Image_Composer_Editor`: `wp_get_image_editor` WP_Error, resize WP_Error,
  upscale-guard size mismatch, save WP_Error, zero-width target geometry guard, missing-role
  guard, and successful OG resize happy path. Orchestrator ladder test
  `test_gd_rung_emits_passthrough_featured_and_single_warning` added to `ImageComposerTest.php`,
  asserting the Imagick renderer is never called on the `gd` rung and pass-through featured is
  always first.
  (Source: QA verdict `Peptide Repo CTO/qa-reviews/prautoblogger/2026-06-11-6f76df1.md` P2-1.)

### Fixed
- **Dead layout keys removed.** `caption_margin_right` (og) and `caption_side_padding` (square)
  were defined in `Layout::defaults()` and exposed via the `prautoblogger_image_compose_layout`
  filter but never consumed by the Imagick renderer. The og renderer positions text from
  `logo_x + logo_w + caption_gap` (left-anchor); the square renderer uses centered `x` alignment.
  Right-edge overflow is enforced via `caption_chars_per_line` on both variants. Keys removed;
  filter users tuning these values had no effect and received no error.
  (Source: QA verdict `Peptide Repo CTO/qa-reviews/prautoblogger/2026-06-11-6f76df1.md` P3-3.)
- **Amortized research row now carries audit fields.** `Post_Assembler::amortize_research_costs()`
  previously inserted per-article `llm_research` copies without `prompt_version` or `agent_role`,
  leaving those columns NULL/empty while the original (correctly stamped) row was deleted.
  SELECT now includes both columns; INSERT carries them forward from the original row.
  (Source: phase-1 thread seq 49, seq 50 carried-list: "amortized llm_research rows pv=null/role
  empty" — `convo/prautoblogger/threads/2026-06-pipeline-v2-phase1/`.)
- **`fetch_page()` error body included in exception.** Non-200 responses from the Runware
  modelSearch API now include up to 200 chars of the response body in the `RuntimeException`
  message, making diagnosis from logs actionable without re-reproducing the request.
  (Source: phase-1 thread seq 50 carried-list: "`fetch_page()` error-body logging".)

### Notes
- cto-review.yml isinstance guard (handoff item 5): the fix (`c.get("severity", "UNKNOWN")`)
  is already applied at HEAD (line 454 of the workflow). No changes made.

## [0.19.3] - 2026-06-12

### Fixed
- **Dossier page 403 (P0 hotfix).** `PRAutoBlogger_Dossier_Page::on_register_menu` registered the
  dossier as a hidden submenu under `prautoblogger-settings` then called `unset($submenu[...])` to
  hide it from the nav. This is the **hide-by-unset anti-pattern**: at registration time WP records
  the render callback under hookname `prautoblogger_page_prautoblogger-dossier`; at request time
  `get_admin_page_parent()` scans `$submenu`, finds the slug absent (it was unset), returns false,
  and WP recomputes the hookname under the orphan `admin_page_*` namespace -- no handler registered
  there -- `wp_die(403)`. Same hookname-mismatch class as the board 404 (v0.19.1), different vector.
  **Fix:** register under parent `options.php` (canonical hidden-page pattern). Hookname is
  `admin_page_prautoblogger-dossier` at both registration and request time. No `$submenu` mutation
  required or permitted. Asset enqueue gate updated to match (`admin_page_*` prefix).
  Deep-link URLs (`admin.php?page=prautoblogger-dossier&post_id=N`) are parent-agnostic and
  unchanged. Board cards and the post metabox link continue to work without modification.

### Changed
- **Regression test upgraded to request-time faithful** (`DossierMenuRegistrationTest`).
  New `test_request_time_hookname_matches_registration_hookname` simulates
  `get_admin_page_parent()` against the post-registration `$submenu` state and asserts it
  equals the registration-time hookname. FAILS on hide-by-unset (4 failures on v0.19.2 code,
  captured in test evidence). PASSES on options.php-parent. New
  `test_hide_by_unset_would_fail_request_time_check` documents the broken pattern inline.
- **CONVENTIONS.md §Hidden Admin Pages** added: options.php-parent is the only permitted
  pattern for hidden admin pages; `$submenu` mutation after registration is explicitly
  prohibited; full incident history (board 404 + dossier 403) and test methodology documented.
- **ARCHITECTURE.md §22b** updated with hidden-page convention cross-reference.
  **§23** updated to reflect options.php-parent registration and the v0.19.2→v0.19.3 fix.
- **tests/bootstrap.php**: added `PRAUTOBLOGGER_PLUGIN_URL` and `PRAUTOBLOGGER_VERSION`
  constants so admin enqueue tests resolve without "Undefined constant" errors.

## [0.19.2] - 2026-06-12

### Added
- **Article Dossier page (M2).** Per-article read-only inspection page at
  `admin.php?page=prautoblogger-dossier&post_id=<id>`. Accessible via board card
  deep-links and the slim post metabox link. Shows: header (title, verdict pill, tier,
  status), sidebar (run metadata, per-stage cost receipt with model + prompt-version),
  vertical stage sections (rendered output default, per-stage raw-trace toggle exposing
  `generation_log` request JSON + tokens + cost + role), editorial decisions rationale,
  image A/B pair sections.
- **Board card deep-links.** All Kanban cards now deep-link directly to the article
  dossier page (`dossier_url` on card data; `click_action = dossier` in board JS).
- **Slim post metabox.** `PRAutoBlogger_Post_Metabox` now renders a single "View
  generation dossier →" link (was a verbose generation-metadata dump).
- **Dossier CSS/JS.** `assets/css/dossier.css` (warm editorial palette, verdict pills,
  cost receipt, raw-trace toggle) + `assets/js/dossier.js` (vanilla JS toggle,
  `aria-expanded` / `aria-controls` accessible).
- **Menu registration test coverage.** `DossierMenuRegistrationTest.php` — asserts
  dossier hook fires at priority > board (> parent), uses hidden-submenu pattern,
  correct slug, and `url_for_post()` URL shape. Mirrors `BoardMenuRegistrationTest`.
- **Raw-trace credential check (binding 4).** `request_json` column contains only
  OpenRouter request bodies (model, messages, temperature — no Authorization headers
  per architecture). All model output escaped via `esc_html()` / `wp_kses_post()`;
  treated as untrusted HTML throughout the render path.
- **Legacy graceful state (binding 5).** Posts with no run record (pre-v0.18.0) render
  a clean "No generation record" notice — no notices, no fatals. Amortized research
  rows (pv=null, role='') render model as "—" with no raw-trace toggle.

### Changed
- **`class-prautoblogger.php` → `class-db-migrations.php` split (binding 1).**
  All DB-migration methods extracted byte-identically into new static class
  `PRAutoBlogger_DB_Migrations` (`includes/class-db-migrations.php`, 129 lines).
  `class-prautoblogger.php` shrinks from 407 → 258 lines; delegates via thin proxy.
  Loaded via explicit `require_once` in `prautoblogger.php` (autoloader filename
  collision avoidance — see ARCHITECTURE.md §File Naming Notes).

## [0.19.1] - 2026-06-12

### Fixed
- **Board admin submenu 404 (P0 hotfix).** `PRAutoBlogger_Board_Page::on_register_menu`
  was hooked at `admin_menu` priority 10, identical to
  `PRAutoBlogger_Admin_Page::on_register_menu`. Because the board hook was
  registered first in `register_admin_hooks()`, `add_submenu_page()` fired while
  `$admin_page_hooks['prautoblogger-settings']` was still unset. WordPress fell back
  to the `admin_page_prautoblogger-board` hookname; at request time it recomputed
  `prautoblogger_page_prautoblogger-board`, found no registered callback, and called
  `wp_die()` with "Invalid plugin page". Board CSS/JS also never loaded (same
  enqueue-gate mismatch). Fix: parent menu hook stays at priority 10; board submenu
  hook moves to priority 11, guaranteeing `add_menu_page()` fires first. Board is
  kept as the first submenu item via explicit `$submenu` reorder in
  `PRAutoBlogger_Board_Page::on_register_menu()`.
- **Regression test added:** `tests/unit/Admin/BoardMenuRegistrationTest.php` —
  three assertions covering priority relationship, execution order, and parent-slug
  correctness. Includes a "documents the bug" test that fires the unfixed hook order
  to prove the broken state.

## [0.19.0] - 2026-06-12

### Added
- **Kanban board dashboard (M1 — Phase 2 admin / Direction C).** New
  `Board` submenu page is now the primary landing screen for the plugin.
  Four columns — Generating | In Review | Published | Failed — with card
  click-throughs: Generating/Failed → Activity Log; In Review → Review
  Queue; Published → Post edit screen. (M2 will rewire all to the Article
  Dossier.)
- **Live board polling.** AJAX action `prautoblogger_board_status` (nonce
  `prautoblogger_board`) returns the full board snapshot every N seconds.
  Poll interval backs off to 2× when no article is actively generating
  and resets to base as soon as a generating card appears. All nonce and
  capability checks enforced.
- **Two new settings** in Schedule & Budget: `prautoblogger_board_poll_interval`
  (default 5s, min 3s) and `prautoblogger_board_published_window_days`
  (default 7 days). Both localized into board.js — no hardcoded values.
- New files: `includes/admin/class-board-page.php`,
  `includes/admin/class-board-data-provider.php`,
  `templates/admin/board-page.php`, `assets/css/board.css`,
  `assets/js/board.js`.
- PHPUnit behavioral tests: `tests/unit/Admin/BoardDataProviderTest.php`
  (8 tests covering all column/state mappings) and
  `tests/unit/Core/CostReporterSqlBugFixTest.php` (3 tests for the SQL
  inline-comment fix).

### Fixed
- **Cost-reporter SQL inline-comment bug (P2).** `PRAutoBlogger_Cost_Reporter::get_daily_spend()`
  and `get_spend_by_stage()` embedded PHP `// phpcs:ignore` annotations
  inside SQL string literals, passing the comment text to MySQL. Moved
  phpcs suppression annotations to standalone PHP line comments above the
  `$wpdb->prepare()` call — matching the pattern already used in
  `get_monthly_spend()`. No query behavior change; fixes malformed
  prepared-statement strings visible in debug logging.

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
  WordPress Coding Standards check, and f
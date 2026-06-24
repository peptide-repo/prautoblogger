# PRAutoBlogger — Domain Glossary

Per DoD v1.2.0 §7, this file defines domain terms introduced by structural changes.
Update when a PR introduces new terms not covered here.

For full design rationale see `ARCHITECTURE.md` (§21–§25) and `CONVENTIONS.md`.

---

## Pipeline Settings (introduced v0.23.0)

| Term | Definition |
|------|-----------|
| **step** | One LLM-powered stage in the article-generation pipeline (Research, Analysis, Writer, Editorial, Image). Non-LLM stages (Scoring, Publish) are not surfaced here. |
| **step rail** | The vertical navigation column in the Pipeline Settings page; one button per step. |
| **step panel** | The right-hand content area in the Pipeline Settings page that shows model, params, and prompts for the selected step. |
| **pipeline-ui** | The author tag written to the prompts table when an operator edits a prompt via the Pipeline Settings page (e.g. `pipeline-ui:rhys`). |
| **system instruction** | The system prompt for an LLM step — sets role, constraints, and style for the model. Stored in the prompt registry under a key like `research.system`. |
| **agent prompt** | A user-turn prompt for an LLM step — contains the task the model must perform. Stored under keys like `content.single_pass`, `content.draft`. |
| **system_key** | The registry key string for a step's system instruction (e.g. `research.system`). Defined in `PRAutoBlogger_Pipeline_Settings_Step_Map`. |
| **agent_key** | A registry key string for one of a step's agent/user prompts. A step may have zero or more agent keys. |
| **prompt key** | Generic term covering both system_key and agent_key — a dotted string like `content.single_pass` that uniquely identifies a prompt in the registry. |
| **prompt slug** | URL/form-safe encoding of a prompt key: dots replaced with hyphens (e.g. `content-single_pass`). Used as HTML field values and `?prompt_key=` query params. |
| **reset-to-default** | A Pipeline Settings action that creates a new prompt version with the canonical seed body (`PRAutoBlogger_Prompt_Registry::default_body()`), effectively restoring factory settings for that key. |
| **step map** | `PRAutoBlogger_Pipeline_Settings_Step_Map` — the single source of truth for step metadata, model option keys, and prompt key allowlists. |
| **save handler** | `PRAutoBlogger_Pipeline_Settings_Save_Handler` — stateless class that verifies nonce/cap, validates against allowlists, and persists model or prompt changes. |

## Prompt Registry Terms (introduced v0.16.0–v0.18.0)

| Term | Definition |
|------|-----------|
| **prompt version** | An immutable row in `wp_prautoblogger_prompts`. Once written, the body never changes; a new edit creates a new version row. |
| **active version** | The single version per prompt key flagged `is_active = 1`. Older versions are retained for history/rollback. |
| **seed** | Version 1 of a prompt key, written by the activator migration (`seed_v1()`). Author tag: `seed`. |

---

## Core Domain Terms (introduced v0.22.1)

**run**
A single end-to-end article generation cycle: orchestrate → generate idea(s) →
finalize. Represented as a row in `wp_prautoblogger_runs` (status, cost ceiling,
timestamps). One run may produce multiple articles if the daily idea-count > 1.

**run\_stages** (`wp_prautoblogger_run_stages`)
Sub-steps within a run (research, analysis, generation, publish). Each stage has
its own status and can be replayed independently via `Rerun_Executor`. Generation
checkpoint runs populate stages as Article_Worker progresses.

**checkpoint**
The persistence boundary between pipeline ticks. After each tick the idea queue
and run state are written to `wp_options` so a process kill cannot lose work. The
next tick reads the queue option and continues. See §25 of ARCHITECTURE.md.

**idea queue** (`prautoblogger_article_queue` option)
Serialized array of `Article_Idea` objects written by `on_orchestrate_tick()` and
consumed one-at-a-time by successive `on_generate_tick()` calls. Deleted on
finalize or cleanup.

**Generation\_Lock** (`wp_prautoblogger_generation_lock` or `wp_options`)
DB mutex that prevents two concurrent runs. Acquired at the start of
`on_orchestrate_tick()`, released by `Helpers::finalize()` or on error/halt.

---

## Execution Modes (introduced v0.22.1)

### Async (wp-cron driven)

Default path for the Board "New Article" button and the plain
`wp prautoblogger generate` WP-CLI command.

1. `kick_off()` schedules `prautoblogger_gen_orchestrate` and calls `fire_cron_now()`.
2. `on_orchestrate_tick()` runs in a background PHP process, schedules
   `prautoblogger_gen_tick`, and calls `fire_cron_now()` to spawn the next process.
3. Each `on_generate_tick()` spawns the next until the queue drains.

Limitation: Hostinger kills background PHP processes at ~10 min. Orchestrate tick
(LLM-free) and individual generate ticks (one LLM call each) are both well within
this limit; the checkpoint design is the mitigation.

### Sync (VPS-driven / `--sync`)

Used by the Coolify KVM8 VPS systemd timer via `infra/vps/orchestrate-generation.sh`
in `peptide-e2e`. Introduced v0.22.1.

1. `run_sync()` sets `Generation_Checkpoint_Runner::$sync_mode = true`.
2. Calls `on_orchestrate_tick()` **in-process** (no background spawn).
3. Loops calling `on_generate_tick()` directly until queue empty or terminal state.
4. `$sync_mode = true` suppresses all `wp_schedule_single_event` +
   `fire_cron_now` calls inside both tick methods, preventing a competing
   background cron chain from forming in parallel.

The VPS SSH session is held open; the process is not subject to Hostinger's
background-kill constraint.

---

## Table Shorthand

Throughout the codebase `wp_prautoblogger_*` tables are referenced by their
`$wpdb->` property names (e.g. `$wpdb->prab_runs`, `$wpdb->prab_run_stages`).
Full table names are defined in `class-activator.php`.

---

*See also: `ARCHITECTURE.md` §25 (checkpoint design), `CONVENTIONS.md` (coding rules).*

---

## Pipeline Settings M2 (v0.24.0)

| Term | Definition |
|------|-----------|
| **step context** | A routing key (`global\|research\|analysis\|writer\|editorial`) that identifies which group of option fields is being edited in the `save_step_settings` handler. Validated against the `PRAutoBlogger_Pipeline_Settings_Option_Fields::contexts()` allowlist before any write. Distinct from "step" (the display unit in the step rail) — `global` is a context with no corresponding step button. |
| **Global Content Context** | The editable form block rendered above the step rail in the Pipeline Settings page (v0.24.0). Surfaces `prautoblogger_niche_description` and any future cross-step fields. Uses `step_context=global` in its save form. Not associated with an LLM step. |
| **option field** | A `wp_option`-backed field surfaced in a step panel or the Global Content Context block. Defined in `PRAutoBlogger_Pipeline_Settings_Option_Fields_Data`. Distinct from a "prompt panel" (which edits the prompt registry). Types: `textarea`, `select`, `number`, `toggle`, `checkboxes`. |
| **step option** | Synonym for option field when it appears inside a step panel (i.e., not in the Global Content Context). |
| **`step_context`** | The POST key that routes `save_step_settings` to the correct field set. Expected values: `global`, `research`, `analysis`, `writer`, `editorial`. Validated with `sanitize_key()` before use. |
| **`pipeline_action=save_step_settings`** | POST contract key introduced in M2. Triggers `PRAutoBlogger_Pipeline_Settings_Save_Handler::handle_step_settings_save()`. Parallel to the existing `save_model`, `save_prompt`, and `reset_prompt` action values. |
| **relocated-tabs concept** | The Settings tabs `prautoblogger_models`, `prautoblogger_content`, and `prautoblogger_sources` were retired in M2; their 17 option fields moved to per-step panels in the Pipeline page. The `wp_option` keys are unchanged — only the admin UI surface that edits them moved. See `CONVENTIONS.md §Retired Settings Tabs`. |

---

## Pipeline Settings M3 (v0.25.0)

| Term | Definition |
|------|-----------|
| **assembled instructions** | The fully-rendered text the LLM actually received for a given prompt key — all `{{ token }}` placeholders substituted with live data. Surfaced in the Template/Preview toggle on the Pipeline Settings page. Preferred source: last successful `generation_log.request_json` row for the stage; fallback: sample render with `[token_name]` placeholders. |
| **Template mode** | The default view of a prompt panel: shows the editable textarea with raw template text (including `{{ token }}` placeholders). Editing here writes a new version to the prompt registry. |
| **Preview mode** | Read-only view of a prompt panel: shows the assembled instructions the LLM received in the last run (or a sample render if no run exists). No save path in PHP or JS. |
| **Template-vs-Preview guardrail** | The Template and Preview panes are mutually exclusive. Preview has no save path server-side (no write handler) or client-side (JS sends no save POST when in preview mode). Prevents accidental edits to the rendered output. |
| **version diff** | An LCS-based line-level diff between two prompt versions (or a version and the factory default). Rendered server-side as `{type, text}` lines (added/removed/context/omitted); the JS applies colours without parsing unified-diff format. Context window: 3 lines before/after each changed block. |

---

## Pipeline Settings M4 (v0.26.0)

| Term | Definition |
|------|-----------|
| **Generation History** | The `prautoblogger-gen-history` admin page: paginated list of all pipeline runs, newest first. Entry point to the per-step I/O drill-down. |
| **run row** | A single entry in the Generation History list. Corresponds to one `prautoblogger_runs` ledger row. Shows title (from linked post), date, status chip, models used, cost, duration. |
| **Stage I/O** | The inline drill-down panel for a run: shows every generation_log stage's full INPUT (system + user message content from `request_json`) and OUTPUT (model response from `run_stages.meta_json.output`). Loaded via AJAX on first toggle; cached client-side for subsequent toggles. |
| **input_system** | The `system`-role message content extracted from `generation_log.request_json`. The prompt template the LLM received. May be null for image/non-chat stages. |
| **input_user** | The last `user`-role message content from `request_json`. The rendered, token-filled instruction the LLM received. |
| **output** | The LLM's raw response text from `run_stages.meta_json.output`. Null when the stage is log-only (image_a, image_b, llm_research, image_prompt_rewrite — no run_stages row). |
| **output_pruned** | True when `run_stages.meta_json` exists but has no `output` key — indicating the output was pruned by the `prautoblogger_request_json_retention_days` setting. The UI shows an explicit message instead of a blank field. |
| **log-only stage** | A generation_log stage with no corresponding run_stages row. Image stages (image_a, image_b, image_prompt_rewrite) and llm_research fall here. Their output is null (not pruned) in the drill-down. |

---

## Pipeline Board M5 — Mission Brief (v0.27.0)

| Term | Definition |
|------|-----------|
| **Mission Brief** | The new board layout (CEO-selected Direction C, v0.27.0). Replaces the four-column kanban with a status-grouped vertical run list (Generating / In Review / Published / Failed). Each section is independently expandable; the board name reflects the editorial-command framing of the design. Class: `PRAutoBlogger_Board_Page`. |
| **inspector rail** | The persistent right-hand panel that opens when a run row is clicked. Fetches per-stage I/O via AJAX (`prautoblogger_board_inspector`) and renders the full stage breakdown — status dots, model, cost, expandable prompt/response text, total cost receipt, and an "Open dossier" CTA — without navigating away from the board. Class: `PRAutoBlogger_Board_Inspector_Handler`. |
| **dot-rail** | The lightweight row-level stage-progress indicator in the Mission Brief run list. Each stage in the run is represented by a small dot coloured by its status (e.g. complete = teal, failed = red). Populated by `PRAutoBlogger_Board_Stage_Dots::enrich()`, which adds a `run_stages_summary` key to each card. |
| **run_stages_summary** | A card-level enrichment key added by `PRAutoBlogger_Board_Stage_Dots::enrich()`. Array of `{stage, status}` objects for each stage in the run, sourced from the `wp_prautoblogger_run_stages` table in a single batched query per board section. Consumed by `board.js` to render the dot-rail. |

---

## Phase 2b P2b.1 — Research_Fanout + Research_Judge (v0.28.0)

These terms are introduced by the Authority-tier research pipeline (additive; not wired
into the live Economy path until P2b.4).

| Term | Definition |
|------|-----------|
| **fan-out** | The pattern of dispatching N independent LLM requests in parallel (via `curl_multi`), each covering a different specialist angle of the same topic, then collecting and filtering results. Contrasts with the Economy single-pass where one LLM call produces all research. |
| **Research_Fanout** | `PRAutoBlogger_Research_Fanout` — the orchestrator class that implements the fan-out pattern. Reserves the SUMMED worst-case cost of all N agents from the cost governor before dispatch, applies quorum enforcement after, and returns per-agent result arrays to the caller. Not live until P2b.4 wires it into the tier router. |
| **curate stage** | The pipeline stage (`stage = 'curate'`) that follows fan-out. Implemented by `PRAutoBlogger_Research_Judge`. Deduplicates sources from all agents, scores each by `quality_score`, and writes the top-12 results to `run_sources`. Already present in `Stage_Display_Map` since v0.18.0. |
| **Research_Judge** | `PRAutoBlogger_Research_Judge` — the class that executes the curate stage. Dedup chain: URL-canonical → semantic cosine (OpenRouter embeddings) → keyword-overlap fallback. Scores via `Research_Source_Scorer`. Writes `kept=1` / `kept=0` rows to `run_sources` with `quality_score` and `reason`. |
| **quorum** | Minimum number of research agents that must return usable results for the fan-out to proceed. Formula: ⌈N/2⌉+1 (one strict majority). Example: N=3 → quorum=3; N=5 → quorum=4. If fewer than quorum agents return valid results, `Research_Fanout::dispatch()` returns an empty array and the caller **must hold the run** rather than proceeding on thin research. |
| **specialist role** | One of `{mechanisms, clinical, safety, comparison, practical}`. Each agent in the fan-out is assigned a distinct role and receives a role-specific user prompt. The first N roles in priority order are used (N = `prautoblogger_research_agent_count`). Written to `run_stages` as `agent_role = 'researcher:<role>'`. |
| **quality_score** | A composite score for a source: `relevance × authority_weight`. `relevance` comes from the agent's self-reported score (0.0–1.0). `authority_weight` is the source-type multiplier from `Research_Source_Scorer`. Higher scores sort the source closer to the top-12 kept set. Stored in `run_sources.quality_score`. |
| **authority weight** | A source-type multiplier applied by `PRAutoBlogger_Research_Source_Scorer` to produce `quality_score`. Values: DOI/PubMed/NCBI peer-reviewed → 1.0; .gov/.edu/WHO/FDA institutional → 0.85; preprint → 0.70; HTTPS → 0.60; HTTP → 0.40. Reflects the editorial preference for peer-reviewed sources in Authority-tier articles. |
| **MIN_AGENTS floor** | `Research_Fanout::MIN_AGENTS = 2`. The minimum configurable agent count. Set to 2 (not 1) because quorum = ⌈N/2⌉+1 = 2 for N=1, making a single-agent dispatch mathematically impossible to satisfy. Clamping to 2 ensures at least a 2-agent fan-out where quorum=2 is achievable (both must succeed). |

## Phase 2b P2b.2 — Editorial_Loop (v0.29.0)

These terms are introduced by the Authority-tier bounded editorial loop (additive; not wired
into the live Economy path until P2b.4).

| Term | Definition |
|------|-----------|
| **editorial loop** | The iterative editor↔writer review pattern used in the Authority tier. Each round: `Chief_Editor::review()` critiques the draft → if approved, loop exits; if not, the writer revises the draft via `Editorial_Revision_Caller::call()` → repeat. After max rounds without approval the article is **escalated to the Review Queue**. Contrasts with the Economy single-pass where `Chief_Editor::review()` runs once, no revision loop. |
| **editorial_max_rounds** | The `prautoblogger_editorial_max_rounds` WordPress option (int, default 3, clamped to [1, 10]). Controls how many editor↔writer rounds may occur before escalation. Configurable without a code change. |
| **round record** | An instance of `PRAutoBlogger_Editorial_Round` capturing one completed loop iteration. Fields: `round_number` (1-based), `editor_notes` (critique), `editor_verdict` ('approved'/'revised'/'rejected'), `revised_content` (writer output for that round, '' on approval), `quality_score`, `seo_score`. Serialised via `to_array()` for JSON audit snapshots in `run_stages` and the loop escalation record. |
| **Review Queue escalation** | When `editorial_max_rounds` are exhausted without the editor approving, the article is saved as a WordPress draft (Review Queue) rather than published. `Editorial_Loop::run()` returns `''` and `was_escalated()` returns `true`. One `run_decisions` row is written with `verdict='escalated'` and a rationale noting the max rounds exhausted. |
| **inline revision** | When `Chief_Editor::review()` returns a non-null `revised_content` in the `PRAutoBlogger_Editorial_Review` object (verdict='revised'), that content is used directly for the next round without invoking `Editorial_Revision_Caller`. Reduces LLM calls when the editor can self-correct. |
| **Editorial_Revision_Caller** | `PRAutoBlogger_Editorial_Revision_Caller` — the extracted writer revision step. Builds revision prompts via `Content_Prompts::build_revision_system/user()`, dispatches the writer LLM call (model from `prautoblogger_writing_model`), manages `run_stages` start→done for `role='writer'`, and logs cost. Extracted from `Editorial_Loop` to keep both classes under 300 lines. |

---

## Phase 2b P2b.3 — SEO Stage + citation_score (v0.30.0)

These terms are introduced by the Authority-tier SEO stage (additive; not wired
into the live Economy path until P2b.4).

| Term | Definition |
|------|-----------|
| **SEO stage** | The pipeline stage (`stage = 'seo'`) that writes `_prab_*` post-meta to the published post so that peptide-repo-core can emit JSON-LD schema (Drug/MedicalWebPage). Implemented by `PRAutoBlogger_Seo_Stage`. Deterministic — no LLM calls. |
| **JSON-LD contract v1** | The ratified set of `_prab_*` meta keys agreed between PRAB (writer) and prcore (reader). Canonical source: `convo/prcore/decisions/2026-06-11-jsonld-contract-v1.md`. The SEO stage writes all keys except `_prab_reviewed_by` (P2b.4 only). |
| **citation_score** | Average `quality_score` of kept research sources. Formula: `sum(quality_score) / count(sources)`. Returns `0.0` for empty source lists. Stored as `_prab_citation_score` post-meta (string representation of float). Ranges 0.0–1.0. |
| **citation_score_threshold** | The minimum `citation_score` required to pass the publish gate. Option key: `prautoblogger_citation_score_threshold`. Default `0.0` — intentionally uncalibrated until ~10 Authority runs provide a distribution baseline. The gate itself is in P2b.4; this stage reads and logs the threshold but does not enforce it. |
| **_prab_schema_version** | Integer `1`. The presence of this key on a post is the opt-in trigger for prcore's JSON-LD emission. Absent = no schema emitted (Economy posts never have it). |
| **_prab_review_mode** | String: `editorial-system` (automated SEO stage, set here) or `human` (set in P2b.4 when a human approves via the Review Queue). Only one value is written per post. |
| **_prab_reviewed_at** | ISO 8601 datetime of when the SEO stage executed. Set by `gmdate('Y-m-d\TH:i:s')` at run time. |
| **_prab_reviewed_by** | WP user ID of the Review Queue approver. **NOT written by P2b.3**. Set exclusively by P2b.4 on human approval. |
| **_prab_citations** | JSON-encoded array of kept research sources: `[{url, title, doi?, quality_score?}]`. Derived from the `$kept_sources` array passed by the curate stage. |
| **_prab_about_peptides** | JSON-encoded array of related peptide post IDs. Populated from P2b.4 peptide linkage; defaults to `[]` in P2b.3 (the SEO stage accepts the array as a parameter for future-compatibility). |

## P2b.4 Glossary (Tier Router + Authority Pipeline Orchestrator)

| Term | Definition |
|------|-----------|
| **Tier routing** | The process of routing an article idea to Authority or Economy (single-pass) path. Implemented in `PRAutoBlogger_Tier_Router::resolve()`. |
| **Master flag** | `prautoblogger_authority_pipeline_enabled` (default `false`). When OFF, ALL generation uses Economy path — zero behaviour change from pre-P2b.4. Must be explicitly enabled by an operator. |
| **Category tier map** | `prautoblogger_category_tiers` — serialized array `[category_slug => economy]`. Only `economy` is a meaningful explicit demote; unclassified categories default to Authority. |
| **Authority Pipeline** | `PRAutoBlogger_Authority_Pipeline` — 6-stage orchestrator: research -> curate -> draft -> editorial -> seo -> publish gate. Wires together P2b.1+P2b.2+P2b.3 subsystems. |
| **Citation gate** | Publish gate in the Authority pipeline: `citation_score >= threshold` to publish; else hold as draft with imagery suppressed. Default threshold `0.0` (gate always passes until calibrated). |
| **Imagery gate** | `_prautoblogger_imagery_suppressed = 1` written on held articles. Signals image pipeline to skip generation. Not set on published articles. |
| **Cost ceiling halt** | `PRAutoBlogger_Cost_Ceiling_Exception` caught at Authority pipeline top level. Article saved as draft with status `halted`; never force-completed. |

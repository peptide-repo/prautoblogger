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

# PRAutoBlogger — Domain Glossary

Quick reference for the generation pipeline domain model. For full design rationale
see `ARCHITECTURE.md` (§21–§25) and `CONVENTIONS.md`.

---

## Core Domain Terms

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

## Execution Modes

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

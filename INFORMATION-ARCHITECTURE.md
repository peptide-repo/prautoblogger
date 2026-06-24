# Information Architecture — PRAutoBlogger

Auto-generated index of all admin surfaces, data flows, and integrations.
Maintained per DoD v1.1.0 (same-PR update rule).

---

## Admin Pages

| Slug | Class | Template | Purpose |
|------|-------|----------|---------|
| `prautoblogger-settings` | `PRAutoBlogger_Admin_Page` | `admin-page.php` | Main settings: API Keys, Schedule & Budget, Publishing, Analytics, Display, Images. AI Models / Content / Sources retired to Pipeline Settings in M2 (v0.24.0). |
| `prautoblogger-board` | `PRAutoBlogger_Board_Page` | `board-page.php` | Mission Brief board (M5): vertical run-list + right-rail inspector. Status sections: Generating / In review / Published / Failed. New Article button. Stage dot-rail. Inspector: per-stage I/O, cost receipt, dossier link (v0.21.0, v0.27.0). |
| `prautoblogger-pipeline` | `PRAutoBlogger_Pipeline_Settings_Page` | `pipeline-settings-page.php` | Per-step pipeline config: Global Content Context (niche), step option fields, model picker, system instructions, agent prompts, params (M1 v0.23.0; M2 v0.24.0 adds editable step options, retires AI Models/Content/Sources Settings tabs). M3 v0.25.0 adds Template/Preview toggle (assembled-instructions preview from last-run gen_log), version history accordion, and inline diff panel. Three new AJAX endpoints: `prautoblogger_pipeline_preview`, `prautoblogger_pipeline_history`, `prautoblogger_pipeline_diff` (all `manage_options` + nonce gated). P2b.5 v0.32.0 adds three Authority-tier steps to the step rail: **Curate** (research judge model/prompts, Authority only), **SEO** (post-meta model/prompts, Authority only), and **Authority Settings** (master toggle + citation gate + per-category tier map editor). |
| `prautoblogger-gen-history` | `PRAutoBlogger_Gen_History_Page` | `gen-history-page.php` | M4: Paginated list of all generation runs — title, date, status, models, cost, duration; Stage I/O toggle for inline per-step input/output drill-down (AJAX). Hidden options.php-parent page. `manage_options` + `prautoblogger_gen_run_io` nonce. |
| `prautoblogger-dossier` | `PRAutoBlogger_Dossier_Page` | `dossier-page.php` | Per-article generation log + stage edit/re-run (M3). Preferred per-step I/O surface for runs with a linked post. |
| `prautoblogger-ideas` | `PRAutoBlogger_Ideas_Browser` | `ideas-browser.php` | Analysis results browser with per-idea generation |
| `prautoblogger-activity` | `PRAutoBlogger_Activity_Page` | `activity-page.php` | Cost + generation activity log |

---

## Generation Entry Points (v0.21.0)

```
Board "New Article" button
  └─ AJAX prautoblogger_generate_now
       └─ Generation_Status_Poller::on_ajax_generate_now()
            └─ schedules prautoblogger_manual_generation cron
                 └─ Executor::on_manual_generation()
                      └─ Generation_Checkpoint_Runner::on_orchestrate_tick()  ← Tick 1
                           └─ schedules prautoblogger_gen_tick
                                └─ Generation_Checkpoint_Runner::on_generate_tick()  ← Tick 2..N

WP-CLI: wp prautoblogger generate
  └─ WP_CLI_Commands::generate_command()
       └─ Generation_Checkpoint_Runner::kick_off()
            └─ schedules prautoblogger_gen_orchestrate
                 └─ Generation_Checkpoint_Runner::on_orchestrate_tick()  ← Tick 1

Daily auto-generation cron
  └─ prautoblogger_daily_generation
       └─ Executor::on_cron_run()
            └─ Pipeline_Runner::run()  (legacy synchronous path — runs in a single PHP process)
```

---

## Database Tables

| Table | DB Version | Purpose |
|-------|-----------|---------|
| `prautoblogger_sources` | 1.0.0 | Collected Reddit/RSS source content |
| `prautoblogger_analysis_results` | 1.0.0 | Scored article ideas |
| `prautoblogger_generation_log` | 1.0.0 | Per-LLM-call log (cost, tokens, status) |
| `prautoblogger_runs` | 1.0.0 | Per-run ledger (status, ceiling_usd) |
| `prautoblogger_run_stages` | 1.2.0 | Per-stage state for M3 re-run |
| `prautoblogger_stage_inputs` | 1.3.0 | Immutable input versions for stage edits (M3) |
| `prautoblogger_run_decisions` | 1.2.0 | Editorial decisions per run |

DB version history: 1.0→1.1 (schema normalisation), 1.1→1.2 (M2 dossier), 1.2→1.3 (M3 stage inputs), 1.3→1.4 (M4 indexes).

---

## Key Options

| Option | Type | Default | Purpose |
|--------|------|---------|---------|
| `prautoblogger_board_column_limit` | int | 20 | Max runs per board section |
| `prautoblogger_ideas_per_page` | int | 30 | Ideas browser page size |
| `prautoblogger_article_queue` | array | — | Checkpoint queue (run_id + ideas + results) |
| `prautoblogger_checkpoint_run_id` | string | — | Current run UUID for Tick 2..N finalize path |
| `prautoblogger_db_version` | string | — | Installed schema version (triggers dbDelta on mismatch) |
| `prautoblogger_authority_pipeline_enabled` | toggle | 0 | Master switch: ON routes Authority-tier categories through the 6-stage pipeline; OFF = Economy single-pass for all (default, no behaviour change on upgrade) |
| `prautoblogger_category_tiers` | array | [] | Per-category slug → tier map (`authority`\|`economy`). Parsed from the tier-map textarea editor on save. Empty = all categories default to Authority. |
| `prautoblogger_citation_score_threshold` | float | 0 | Minimum source quality score (0–100) for auto-publish gate. 0 = gate disabled while calibrating. |
| `prautoblogger_research_agent_count` | int | 3 | Number of parallel research fan-out agents (1–5). Authority tier only. |
| `prautoblogger_editorial_max_rounds` | int | 3 | Max rounds in the bounded editorial loop (1–5). Authority tier only. |
| `prautoblogger_curate_model` | string | — | Model used for the Curate stage (research judge LLM). Authority tier only. |
| `prautoblogger_seo_model` | string | — | Model used for the SEO stage post-meta enrichment. Authority tier only. |

---

## WP-CLI Commands

| Command | Since | Description |
|---------|-------|-------------|
| `wp prautoblogger generate` | 0.21.0 | Queue article generation via chained-cron checkpoints |
| `wp prautoblogger opik:eval` | 0.12.0 | Trigger Opik eval run |

---

*Last updated: v0.32.1 (2026-06-24)*

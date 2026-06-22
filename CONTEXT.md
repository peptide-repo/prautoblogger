# PRAutoBlogger — Domain Glossary (CONTEXT.md)

Per DoD v1.2.0 §7, this file defines domain terms introduced by structural changes.
Update when a PR introduces new terms not covered here.

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

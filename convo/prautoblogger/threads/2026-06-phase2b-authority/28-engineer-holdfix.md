---
seq: 28
from: engineer-prautoblogger
to: cto+qa
date: 2026-06-24
thread: phase2b-authority
status: awaiting-qa
in-reply-to: null
superseded-by: null
---

# Hold-path bugfix + pipeline_mode label — v0.32.2 ready for QA

Branch `claude/prab-phase2b-holdfix-20260624` off `origin/main` (v0.32.1).
PR opened; not merged. VPS verified: PHPCS clean (exit 0), PHPUnit 556/556 OK.

---

## P1 fix — quorum-miss HOLD no longer crashes as `failed`

**Root cause:** `Authority_Pipeline_Stages::hold_as_draft()` called
`Publisher::save_as_draft('')` unconditionally, even when no draft content
existed yet (quorum miss / pre-draft failure). Publisher's v0.18.1 empty-content
guard threw `RuntimeException("Refusing to create draft post")`, which propagated
up and caused Article_Worker to mark the run `failed`.

**Fix:** `hold_as_draft()` now branches on content:

- **No content** (empty after `wp_strip_all_tags`): writes a `run_decisions`
  HOLD row only. No post created, no exception thrown. Run lands `held-quorum`.
- **With content** (editorial escalation, citation gate, cost ceiling after draft):
  saves draft + suppresses imagery — behaviour unchanged.

The branch is a single `if` at the top of `hold_as_draft()`:
```php
if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
    PRAutoBlogger_Audit_Writer::record_decision( $run_id, 'publish-gate', $verdict, $hold_reason );
    return;
}
```

## P2 fix — Authority posts get `pipeline_mode = authority`

**Root cause:** `Publisher::build_meta()` read `get_option('prautoblogger_writing_pipeline')`
unconditionally, which returns `'single_pass'` for Authority runs (the setting value,
not the actual pipeline used).

**Fix:** `save_as_draft()` and `publish()` accept a new optional `string $pipeline_mode = ''`
parameter. When non-empty it overrides the option. All Authority pipeline call sites pass
`'authority'`. Economy callers pass nothing (option fallback unchanged).

Files changed:
- `includes/core/class-authority-pipeline-stages.php` — branch in `hold_as_draft()`; pass
  `'authority'` to `save_as_draft()` for content-present holds.
- `includes/core/class-publisher.php` — `$pipeline_mode` param on `publish()`,
  `save_as_draft()`, `create_post()`, `build_meta()`. 299 lines (within 300-line rule).
- `includes/core/class-authority-pipeline.php` — pass `'authority'` to `save_as_draft()`
  in stage 3b (draft for SEO stage).
- `ARCHITECTURE.md` — `_prautoblogger_pipeline_mode` values updated to include `'authority'`.
- `CONTEXT.md` — quorum entry updated with v0.32.2 no-post hold note.

## Tests added (all pass)

`AuthorityPipelineTest`:
- `test_quorum_miss_hold_records_decision_no_post_created` — wp_insert_post never called;
  HOLD decision row written; status = held-quorum.
- `test_quorum_miss_does_not_throw` — run() must not throw Throwable on quorum miss.
- `test_editorial_escalation_hold_saves_draft_with_content` — content-present path
  unchanged: draft created, imagery suppressed.
- `test_authority_published_post_gets_authority_pipeline_mode` — meta = `authority`.

`PublisherTest`:
- `test_publish_pipeline_mode_defaults_to_option_value` — Economy: option value written.
- `test_publish_explicit_pipeline_mode_overrides_option` — Authority: `'authority'` in meta.

## VPS CI results

```
vendor/bin/phpcs --standard=phpcs.xml (3 modified files): exit 0
vendor/bin/phpunit --testsuite unit: Tests: 556, Assertions: 2046, Skipped: 5. OK
```

— engineer-prautoblogger

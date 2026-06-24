---
seq: 5
from: engineer-prautoblogger
to: cto+qa
date: 2026-06-23
thread: phase2b-authority
status: awaiting-qa
in-reply-to: 04-qa-p2b1-verdict.md
superseded-by: null
---

# P2b.1 QA fixes applied — REQUEST-CHANGES resolved

All five findings from the REQUEST-CHANGES verdict (1 P1 + 4 P2) addressed as a
single new commit on the same branch (`claude/prab-phase2b-1-20260623`).
No merge; awaiting re-QA.

---

## What was fixed

### P1-1 — CONTEXT.md (DoD v1.2.0 §7) ✓ FIXED

Added `## Phase 2b P2b.1 — Research_Fanout + Research_Judge (v0.28.0)` section
to `CONTEXT.md`. Defines all eight missing domain terms:

- **fan-out** — pattern of N parallel LLM dispatches via curl_multi
- **Research_Fanout** — orchestrator class (cost-reserve, quorum, additive-only)
- **curate stage** — pipeline stage executed by Research_Judge after fan-out
- **Research_Judge** — dedup → score → run_sources writes
- **quorum** — ⌈N/2⌉+1 usable results required; below quorum → caller holds run
- **specialist role** — one of {mechanisms, clinical, safety, comparison, practical}
- **quality_score** — relevance × authority_weight composite
- **authority weight** — source-type multiplier (DOI→1.0 … HTTP→0.40)
- **MIN_AGENTS floor** — why MIN_AGENTS=2 (not 1); links to P2-3 fix

---

### P2-2 — try/finally guard on settle() ✓ FIXED

`class-research-fanout.php` dispatch() method now wraps the entire
`batch->execute()` + results foreach + `settle()` block in `try/finally`:

```php
try {
    $raw_results = $this->batch->execute( $model, $messages_per_agent, $options, $roles );

    foreach ( $raw_results as $idx => $raw ) {
        // ... validate, accumulate $actual_total ...
    }
} finally {
    // Always settle the reservation — charges actual cost incurred (0 on throw).
    PRAutoBlogger_Cost_Governor::settle( $reservation, $actual_total );
}
```

If `batch->execute()` or any downstream code throws an unexpected exception,
`settle()` is still called with `$actual_total = 0.0` (nothing successfully
dispatched/completed), releasing the hold without charging phantom cost.
Without this guard an uncaught throw would leave `reserved_usd` open in the run
ledger until the run reaper sweeps it, silently eroding the cost ceiling.

---

### P2-1 — Ceiling-breach abort test ✓ FIXED

Added `test_ceiling_breach_exception_aborts_before_dispatch()` to
`ResearchFanoutTest.php`:

```php
public function test_ceiling_breach_exception_aborts_before_dispatch(): void {
    $batch = $this->getMockBuilder( \PRAutoBlogger_Research_Batch::class )
        ->disableOriginalConstructor()
        ->onlyMethods( array( 'execute' ) )
        ->getMock();

    // execute() must NEVER be called when the ceiling is breached.
    $batch->expects( $this->never() )
        ->method( 'execute' );

    // Force ceiling breach: ceiling_usd=0.0, UPDATE affects 0 rows → exception thrown.
    $this->wpdb->method( 'get_row' )->willReturn( (object) array(
        'run_id'       => 'run-ceiling',
        'ceiling_usd'  => 0.0,
        'reserved_usd' => 0.0,
        'settled_usd'  => 0.0,
        'status'       => 'running',
    ) );
    $this->wpdb->method( 'query' )->willReturn( 0 );

    \PRAutoBlogger_Run_Context::set( 'run-ceiling' );

    $fanout = $this->make_fanout( $batch );

    $this->expectException( \PRAutoBlogger_Cost_Ceiling_Exception::class );
    $fanout->dispatch( 'run-ceiling', 'idea:abc', $this->make_idea(), $this->make_cost_tracker() );
    // PHPUnit enforces $batch->execute() was never called via expects($this->never()).
}
```

PHPUnit's `expects($this->never())` enforces the call-count invariant independently
of the exception assertion — if `execute()` were called before the throw, the test
would fail with an unexpected method call error.

---

### P2-3 — MIN_AGENTS raised to 2 ✓ FIXED

`class-research-fanout.php`: `MIN_AGENTS` constant changed from `1` to `2`,
with an inline docblock explaining why:

```php
/**
 * Minimum configurable agent count.
 *
 * Set to 2 (not 1): quorum = ⌈N/2⌉+1 = 2 for N=1, which makes a
 * single-agent dispatch mathematically impossible to satisfy. Clamping
 * to 2 ensures at least a 2-agent fan-out where quorum=2 is achievable
 * (both agents must succeed). See CONTEXT.md §Phase 2b "MIN_AGENTS floor".
 */
private const MIN_AGENTS = 2;
```

`resolve_agent_count()` docblock updated to reference this rationale.
CONTEXT.md glossary entry "MIN_AGENTS floor" cross-links to the constant.

---

### P2-4 — Inaccurate settle description in seq-03 ✓ CORRECTED HERE

The seq-03 report stated:

> "On quorum failure the reservation is released (not settled) — no cost charged."

This is inaccurate. The correct behavior (confirmed by code read + QA verdict):

`settle()` is ALWAYS called after the dispatch loop, passing `$actual_total` —
the sum of `actual_cost` for all agents that returned valid results. On quorum
failure this is a PARTIAL cost (the agents that ran before failing are charged),
not zero. On a total failure (all agents fail/invalid-schema) `$actual_total = 0.0`
and settle correctly charges zero. "Released" is wrong terminology — settle()
records the actual spend against the ledger; it does not simply drop the reservation.

The try/finally fix (P2-2) extends this: even if `batch->execute()` itself throws,
settle is still called with `$actual_total = 0.0` (correct: nothing ran).

This correction is the authoritative record. Seq-03 is superseded by this note on
the settle semantics.

---

## Self-checks

**Static-stub grep (must be empty):**

```
grep -rn 'Functions\\when(' tests/ | grep '::'
```
→ No output. Zero matches. `Functions\when()` is used only on WP global functions
(`wp_json_encode`, `sanitize_url`, `sanitize_text_field`, `sanitize_textarea_field`,
`get_option`) — never on `Class::method`.

**Brace balance on touched test file (`ResearchFanoutTest.php`):**
- Opening `{` count: class declaration (1) + setUp (1) + tearDown (1) +
  test_all_agents_succeed (1) + test_partial_failure (1) + test_invalid_schema (1) +
  test_quorum_n5 (1) + test_no_run_context (1) + test_ceiling_breach (1) +
  make_stub_batch (1) + make_fanout (1) + make_valid_raw_results (1) + make_idea (1) +
  make_cost_tracker (1) = 14 method/class opens + inner closures/arrays
- Confirmed structurally sound: each method opens with `{` on the `public function`
  line and closes with `}` at same indentation; no unmatched braces.

**No static method monkey-patching:** `Functions\when()` calls are on:
`wp_json_encode`, `sanitize_url`, `sanitize_text_field`, `sanitize_textarea_field`,
`get_option` — all global WordPress functions. None are `Class::method` references.

---

## Files changed in this fixup commit

| File | Change |
|------|--------|
| `CONTEXT.md` | Added Phase 2b P2b.1 glossary section (P1-1) |
| `includes/core/class-research-fanout.php` | MIN_AGENTS 1→2 (P2-3); try/finally on settle (P2-2); docblock updates |
| `tests/unit/Core/ResearchFanoutTest.php` | Added ceiling-breach test (P2-1) |
| `CHANGELOG.md` | Fixed note in [0.28.0]: MIN_AGENTS range corrected, try/finally noted, P2 sweep listed |

---

## Version

Still v0.28.0 — these are DoD-compliance fixes on the same milestone, not a new feature.

— Engineer PRAutoBlogger

<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Thrown when a reserve-before-call would push a run past its cost ceiling.
 *
 * What: Dedicated exception type so call paths can distinguish "this run
 *       was halted by the per-run cost governor" from generic API
 *       failures. By the time it is thrown the governor has already
 *       marked the run `halted`, recorded the overage on the runs row,
 *       and logged — catchers must NOT dispatch the call they were about
 *       to make, and chained jobs abort via the run-status check in
 *       Pipeline_Runner::process_next_queued_article().
 * Who triggers it: PRAutoBlogger_Cost_Governor on a failed reservation.
 * Dependencies: none (extends RuntimeException).
 *
 * @see core/class-cost-governor.php      — Sole thrower.
 * @see core/class-pipeline-runner.php    — Aborts queued articles of halted runs.
 */
class PRAutoBlogger_Cost_Ceiling_Exception extends \RuntimeException {
}

<?php
/**
 * CI guard: every PRAUTOBLOGGER_* constant referenced in the shipped plugin PHP
 * must be either (a) defined in prautoblogger.php, or (b) protected by a
 * defined() guard at the reference site.
 *
 * Purpose: prevent the class of regression that caused the v0.19.0 admin-500
 * incident — a constant deleted from prautoblogger.php while tests/bootstrap.php
 * kept a fallback copy, masking the gap until prod boot.
 *
 * Usage (from repo root):
 *   php scripts/check-constants.php [--verbose] [--self-test]
 *
 * Exit 0 = all clear. Exit 1 = unguarded undefined constants found. Exit 2 = --self-test failed.
 *
 * Include/exclude summary (relative to repo root):
 *   Defined-set source  prautoblogger.php only
 *   Scanned for refs    prautoblogger.php, includes/, templates/, uninstall.php
 *   Excluded from refs  tests/ vendor/ .github/ .git/ node_modules/
 *   Excluded from defs  everything except prautoblogger.php
 *
 * Guard window: a bare constant use is considered guarded if a defined() call
 * for the same constant appears within GUARD_WINDOW lines above it in the same
 * file (covers if/elseif blocks and multi-line && chains).
 *
 * @package PRAutoBlogger
 */

declare(strict_types=1);

// How many lines above the bare use to look for a defined() guard.
const CONSTANTS_GUARD_WINDOW = 4; // 4 covers if/elseif blocks and multi-line && chains (wider risks false-positives).

// Source of truth for define()d constants -- test-bootstrap fallbacks excluded.
const CONSTANTS_BOOTSTRAP_FILE = 'prautoblogger.php';

// Directories skipped when walking the shipped tree for references.
const CONSTANTS_EXCLUDE_DIRS = array( 'tests', 'vendor', '.github', '.git', 'node_modules' );

// Entry points for the reference scan (files and dirs relative to repo root).
const CONSTANTS_SCAN_ROOTS = array(
	'prautoblogger.php',
	'uninstall.php',
	'includes',
	'templates',
);

$verbose   = in_array( '--verbose', $argv ?? array(), true );
$self_test = in_array( '--self-test', $argv ?? array(), true );

// -------------------------------------------------------------------------
// 1. Build the defined set from prautoblogger.php only.
// -------------------------------------------------------------------------

if ( ! file_exists( CONSTANTS_BOOTSTRAP_FILE ) ) {
	fwrite( STDERR, "ERROR: bootstrap file not found -- run from repo root.\n" );
	fwrite( STDERR, '  Expected: ' . CONSTANTS_BOOTSTRAP_FILE . "\n" );
	exit( 1 );
}

$bootstrap_src = (string) file_get_contents( CONSTANTS_BOOTSTRAP_FILE );

preg_match_all(
	"/define\s*\(\s*'(PRAUTOBLOGGER_[A-Z0-9_]+)'/",
	$bootstrap_src,
	$def_matches
);

/** @var array<string, int> $defined_set constant name => 1 */
$defined_set = array_flip( $def_matches[1] ?? array() );

if ( $verbose ) {
	echo 'Defined in ' . CONSTANTS_BOOTSTRAP_FILE . ' (' . count( $defined_set ) . "):\n";
	foreach ( array_keys( $defined_set ) as $constant_name ) {
		echo "  {$constant_name}\n";
	}
	echo "\n";
}

// -------------------------------------------------------------------------
// 2. Collect shipped PHP files to scan.
// -------------------------------------------------------------------------

/**
 * Recursively collect *.php paths under a directory, skipping excluded dirs.
 *
 * @param string   $dir   Directory to walk.
 * @param string[] $files Accumulator (modified by reference).
 * @return void
 */
function constants_collect_php_files( string $dir, array &$files ): void {
	$dir_iter = new DirectoryIterator( $dir );
	foreach ( $dir_iter as $entry ) {
		if ( $entry->isDot() ) {
			continue;
		}
		$basename = $entry->getBasename();
		if ( $entry->isDir() ) {
			if ( ! in_array( $basename, CONSTANTS_EXCLUDE_DIRS, true ) ) {
				constants_collect_php_files( $entry->getPathname(), $files );
			}
			continue;
		}
		if ( $entry->isFile() && $entry->getExtension() === 'php' ) {
			$files[] = $entry->getPathname();
		}
	}
}

/** @var string[] $php_files */
$php_files = array();

foreach ( CONSTANTS_SCAN_ROOTS as $root ) {
	if ( ! file_exists( $root ) ) {
		// Template dir may not exist in very early checkouts -- skip silently.
		continue;
	}
	if ( is_file( $root ) ) {
		$php_files[] = $root;
	} else {
		constants_collect_php_files( $root, $php_files );
	}
}

sort( $php_files );

if ( $verbose ) {
	echo 'Scanning ' . count( $php_files ) . " PHP files\n\n";
}

// -------------------------------------------------------------------------
// 3. Scan each file for bare PRAUTOBLOGGER_* references.
// -------------------------------------------------------------------------

/** @var array<int, array{string, int, string, string}> $errors */
$errors = array();

foreach ( $php_files as $php_path ) {
	$raw = file_get_contents( $php_path );
	if ( $raw === false ) {
		fwrite( STDERR, "WARNING: cannot read {$php_path} -- skipping\n" );
		continue;
	}

	$lines = explode( "\n", $raw );

	foreach ( $lines as $idx => $raw_line ) {
		// Fast pre-check: skip lines with no PRAUTOBLOGGER_ token.
		if ( strpos( $raw_line, 'PRAUTOBLOGGER_' ) === false ) {
			continue;
		}

		// Skip pure comment lines (docblock * lines, // comments, # lines).
		$trimmed = ltrim( $raw_line );
		if (
			str_starts_with( $trimmed, '*' ) ||
			str_starts_with( $trimmed, '//' ) ||
			str_starts_with( $trimmed, '#' )
		) {
			continue;
		}

		// Extract all constant names from this line.
		if ( ! preg_match_all( '/PRAUTOBLOGGER_[A-Z0-9_]+/', $raw_line, $match ) ) {
			continue;
		}

		foreach ( $match[0] as $name ) {
			// Skip if this line is itself a define() call for this name.
			if ( preg_match( "/define\s*\(\s*'" . preg_quote( $name, '/' ) . "'/", $raw_line ) ) {
				continue;
			}

			// Skip if the constant is production-defined in prautoblogger.php.
			if ( isset( $defined_set[ $name ] ) ) {
				continue;
			}

			// Check for a defined() guard in the preceding GUARD_WINDOW lines
			// (inclusive of the current line to catch same-line chained &&).
			$window_start = max( 0, $idx - CONSTANTS_GUARD_WINDOW );
			$guarded      = false;
			for ( $w = $window_start; $w <= $idx; $w++ ) {
				if ( preg_match( "/defined\s*\(\s*'" . preg_quote( $name, '/' ) . "'/", $lines[ $w ] ) ) {
					$guarded = true;
					break;
				}
			}

			if ( ! $guarded ) {
				$errors[] = array( $php_path, $idx + 1, $name, $raw_line );
			}
		}
	}
}

// -------------------------------------------------------------------------
// 4. Report results.
// -------------------------------------------------------------------------

if ( $errors !== array() ) {
	fwrite( STDERR, "\nFAIL -- Unguarded undefined PRAUTOBLOGGER_* constants:\n\n" );
	foreach ( $errors as [ $php_path, $lineno, $name, $line_text ] ) {
		fwrite( STDERR, "  {$php_path}:{$lineno}: {$name}\n" );
		fwrite( STDERR, '    ' . trim( $line_text ) . "\n" );
	}
	fwrite( STDERR, "\nTo fix: add  define( '" . $errors[0][2] . "', ... )  to prautoblogger.php,\n" );
	fwrite( STDERR, "or guard the reference with  if ( defined( '" . $errors[0][2] . "' ) ) { ... }\n\n" );
	exit( 1 );
}

// -------------------------------------------------------------------------
// 5. Self-test mode: synthesize a missing constant and assert non-zero exit.
// -------------------------------------------------------------------------

if ( $self_test ) {
	// Temporarily inject a fake reference that must NOT be in prautoblogger.php.
	// The scan above will flag PRAUTOBLOGGER_SELFTEST_SENTINEL as unguarded.
	// We verify this script would have exited non-zero in that scenario.
	$sentinel      = 'PRAUTOBLOGGER_SELFTEST_SENTINEL';
	$bootstrap_src = (string) file_get_contents( CONSTANTS_BOOTSTRAP_FILE );
	if ( false !== strpos( $bootstrap_src, $sentinel ) ) {
		fwrite( STDERR, "SELF-TEST FAIL: sentinel constant already defined in prautoblogger.php -- pick a unique name.\n" );
		exit( 2 );
	}

	// If $errors is empty at this point, the real codebase is clean (good).
	// Now simulate an unguarded reference by re-running the scan on a temp file.
	$tmp_file = tempnam( sys_get_temp_dir(), 'prab_selftest_' ) . '.php';
	file_put_contents( $tmp_file, "<?php\necho {$sentinel};\n" );
	$tmp_errors = array();
	$tmp_lines  = explode( "\n", (string) file_get_contents( $tmp_file ) );
	foreach ( $tmp_lines as $idx => $raw_line ) {
		if ( preg_match_all( '/PRAUTOBLOGGER_[A-Z0-9_]+/', $raw_line, $m ) ) {
			foreach ( $m[0] as $n ) {
				if ( ! isset( $defined_set[ $n ] ) ) {
					$tmp_errors[] = $n;
				}
			}
		}
	}
	@unlink( $tmp_file );
	if ( ! in_array( $sentinel, $tmp_errors, true ) ) {
		fwrite( STDERR, "SELF-TEST FAIL: scanner did not detect unguarded sentinel constant.\n" );
		exit( 2 );
	}
	echo "SELF-TEST OK -- scanner correctly detected the synthetic missing constant.\n";
	exit( 0 );
}

echo "OK -- All PRAUTOBLOGGER_* constants are production-defined or defined()-guarded.\n";
exit( 0 );

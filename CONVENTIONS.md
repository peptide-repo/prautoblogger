# PRAutoBlogger — Conventions

This document codifies the naming patterns, coding conventions, and step-by-step guides for extending PRAutoBlogger. Update this file whenever a new pattern is introduced.

---

## Naming Conventions

### PHP Classes
- **Format:** `PRAutoBlogger_{PascalCase}` (e.g., `PRAutoBlogger_Content_Generator`)
- **File naming:** `class-{kebab-case}.php` (e.g., `class-content-generator.php`)
- **Interfaces:** `interface-{kebab-case}.php` with `PRAutoBlogger_{Name}_Interface` class name

### Hooks (Actions & Filters)
- **Prefix:** All hooks start with `prautoblogger_`
- **Actions:** `prautoblogger_{event}` (e.g., `prautoblogger_before_content_generation`)
- **Filters:** `prautoblogger_filter_{what}` (e.g., `prautoblogger_filter_article_idea_score`)
- **Hook callbacks registered in:** `class-prautoblogger.php` (central loader), never in the classes themselves

### Database
- **Table prefix:** `{$wpdb->prefix}prautoblogger_` (e.g., `wp_prautoblogger_source_data`)
- **Option prefix:** `prautoblogger_` (e.g., `prautoblogger_openrouter_api_key`)
- **Post meta prefix:** `_prautoblogger_` (underscore prefix hides from custom fields UI)
- **Transient prefix:** `prautoblogger_` (e.g., `prautoblogger_reddit_token`)

### Constants
- **Format:** `PRAUTOBLOGGER_{UPPER_SNAKE}` (e.g., `PRAUTOBLOGGER_MAX_RETRIES`)
- **Defined in:** `prautoblogger.php` (bootstrap file) for global constants

### Hook Callback Methods
- **Action callbacks:** Prefixed with `on_` (e.g., `on_daily_generation_triggered`)
- **Filter callbacks:** Prefixed with `filter_` (e.g., `filter_content_quality`)

---

## Error Handling & Logging

### Structured Logger
All logging uses `PRAutoBlogger_Logger` (singleton) instead of raw `error_log()`. The logger writes to the `prab_event_log` custom table and forwards errors/warnings to PHP's `error_log()` for server-level monitoring. The verbosity threshold is user-configurable via Settings → Publishing → Log Level.

**Never call `error_log()` directly.** Always use the Logger:

```php
$logger = PRAutoBlogger_Logger::instance();
$logger->error( 'API call failed: ' . $error_message, 'openrouter' );
$logger->warning( 'Budget nearing limit.', 'cost-tracker' );
$logger->info( 'Pipeline complete: 3 posts generated.', 'pipeline' );
$logger->debug( 'Token counts: prompt=1200, completion=800', 'openrouter' );
```

### Log levels (ascending verbosity)
| Level   | Numeric | Use for |
|---------|---------|---------|
| error   | 0       | Failures: API errors, parse failures, DB write failures |
| warning | 1       | Degraded behavior: budget approaching, missing providers, fallback pricing |
| info    | 2       | Key events: pipeline start/stop, articles generated, metrics collected |
| debug   | 3       | Verbose detail: token counts, timing, skipped posts, intermediate values |

### Context strings
The second argument to every log call is a short context tag identifying the origin:
`pipeline`, `scheduler`, `collector`, `analyzer`, `editor`, `openrouter`, `reddit`, `ga4`, `metrics`, `cost-tracker`, `encryption`.

### What gets shown to the user
- API key issues → admin notice (persistent until resolved)
- Budget exceeded → admin notice + email to site admin
- Generation failures → logged to `prab_generation_log` with `response_status = 'error'`
- Generation failures also shown in the admin dashboard widget
- All log entries visible in PRAutoBlogger → Activity Log (filterable by level)

### What gets retried
- LLM API calls: exponential backoff, max 3 retries, then fail loudly
- Reddit API calls: exponential backoff, max 3 retries, then skip this collection cycle
- **Never retry silently.** Every retry is logged. Every final failure is surfaced.

### Error severity levels
1. **Fatal:** Plugin cannot function (missing API key, budget exhausted) → admin notice, stop all scheduled jobs
2. **Retriable:** Transient API failure → retry with backoff, log each attempt
3. **Skippable:** Single post generation failed → log, skip this post, continue with others

---

## How To: Add a New LLM Provider

1. Create `includes/providers/class-{name}-provider.php`
2. Implement `PRAutoBlogger_LLM_Provider_Interface` (see `interface-llm-provider.php`)
3. Required methods: `send_chat_completion()`, `get_available_models()`, `estimate_cost()`
4. Add provider option to the settings page in `admin/class-admin-page.php`
5. Register the provider in `class-prautoblogger.php` loader
6. Update ARCHITECTURE.md external API integrations table

### Interface contract:
```php
interface PRAutoBlogger_LLM_Provider_Interface {
    public function send_chat_completion(array $messages, string $model, array $options = []): array;
    public function get_available_models(): array;
    public function estimate_cost(string $model, int $prompt_tokens, int $completion_tokens): float;
    public function get_provider_name(): string;
}
```

---

## How To: Add a New Source Provider (Social Platform)

1. Create `includes/providers/class-{platform}-provider.php`
2. Implement `PRAutoBlogger_Source_Provider_Interface` (see `interface-source-provider.php`)
3. Required methods: `collect_data()`, `get_source_type()`, `validate_credentials()`
4. Add API credentials fields to the settings page in `admin/class-admin-page.php`
5. Register the provider in `class-prautoblogger.php` loader
6. Add the source type string to the `ab_source_data.source_type` allowed values
7. Update ARCHITECTURE.md file tree and external API integrations table

### Interface contract:
```php
interface PRAutoBlogger_Source_Provider_Interface {
    public function collect_data(array $config): array; // Returns PRAutoBlogger_Source_Data[]
    public function get_source_type(): string;           // e.g., 'reddit', 'tiktok'
    public function validate_credentials(): bool;
    public function get_rate_limit_status(): array;
}
```

---

## How To: Add a New Image Provider

1. Create `includes/providers/class-{name}-image-provider.php`
2. Implement `PRAutoBlogger_Image_Provider_Interface` (see `interface-image-provider.php`)
3. Required methods: `generate_image()`, `estimate_cost()`, `get_provider_name()`, `validate_credentials_detailed()`
4. Split helpers into companions if you'd exceed the 300-line cap. The Cloudflare provider splits into provider + pricing + validator — mirror that shape.
5. Add API credentials fields to `admin/class-settings-fields.php` under the `prautoblogger_images` section
6. Register the provider in `class-prautoblogger.php` loader once the image pipeline lands (commit 1b of the image workstream)
7. Update `ARCHITECTURE.md` file tree, external API integrations table, and the options table

### Interface contract:
```php
interface PRAutoBlogger_Image_Provider_Interface {
    public function generate_image( string $prompt, int $width, int $height, array $options = [] ): array; // {bytes, mime_type, width, height, model, seed, cost_usd, latency_ms}
    public function estimate_cost( int $width, int $height, array $options = [] ): float;
    public function get_provider_name(): string;                                   // e.g., 'cloudflare_workers_ai'
    public function validate_credentials_detailed(): array;                         // {status, message, debug?}
}
```

### Retry + cost rules (non-negotiable)
- 5xx / 429 / network errors: retry with exponential backoff up to `PRAUTOBLOGGER_MAX_RETRIES`, respect `Retry-After` when present.
- 4xx other than 429: fail loudly on the first response — never retry a deterministic bad request.
- Validation check must be non-destructive (no image generation).
- Every attempt logs via `PRAutoBlogger_Logger` with context `{provider-slug}-image`.

---

## How To: Tune the Image Composer (v0.17.0+)

The deterministic composer (ARCHITECTURE.md #21) renders branded variants between
provider bytes and sideload. Three rules when touching it:

1. **Geometry lives in code, not settings.** All sizes/colors/opacities come from
   `PRAutoBlogger_Image_Composer_Layout::defaults()` and can be overridden via the
   `prautoblogger_image_compose_layout` filter:
   ```php
   add_filter( 'prautoblogger_image_compose_layout', function ( array $layout ): array {
       $layout['featured']['mark_opacity'] = 0.40;
       return $layout;
   } );
   ```
   Promote a value to a settings field only on explicit CEO request.
2. **Keep renderer classes WP-free.** `Image_Composer_Imagick`, `Image_Composer_Canvas`,
   and `Image_Composer_Layout` must not call WordPress functions — they are exercised by
   a standalone render harness (and the determinism test) without a WP bootstrap. Only
   the orchestrator (`Image_Composer`) and the GD rung (`Image_Composer_Editor`) may
   touch WP APIs.
3. **Never fatal a publish.** Any new render path needs a try/catch that degrades to
   pass-through and logs at most one WARNING per run (`warn_once()`). Capability checks
   go through the cached probe (`prautoblogger_image_compose_capability` option +
   same-named override filter) — never re-probe per image.

Adding a new variant role: extend `Layout::defaults()` + the renderer, whitelist the
role in `Image_Composer::configured_variants()` and in
`Image_Attacher::ROLE_POST_META` (new `_prautoblogger_{role}_image_id` post meta keeps
the uninstall prefix-sweep effective), then cover it in the unit ladder tests.

---

## How To: Add a New Admin Setting

1. Define the option key in the settings array in `admin/class-admin-page.php` → `get_settings_fields()` method
2. Format: add an entry to the appropriate section array:
   ```php
   [
       'id'          => 'prautoblogger_new_setting',
       'label'       => __('Setting Label', 'prautoblogger'),
       'type'        => 'text', // text, number, select, textarea, checkbox, password
       'default'     => '',
       'description' => __('Help text shown below the field.', 'prautoblogger'),
       'sanitize'    => 'sanitize_text_field', // sanitization callback
   ]
   ```
3. The settings page renderer handles registration, rendering, and sanitization automatically from this array.
4. Access the setting anywhere with: `get_option('prautoblogger_new_setting', $default)`
5. For encrypted settings (API keys): use `PRAutoBlogger_Encryption::encrypt()` / `decrypt()`

---


---

## Retired Settings Tabs (M2, v0.24.0)

Settings tabs may be retired when their fields are promoted to a more contextual UI
surface (e.g. Pipeline Settings per-step panels). The retirement pattern:

1. **Remove the section** from `PRAutoBlogger_Settings_Fields::get_sections()`.
2. **Remove field definitions** from `get_core_fields()` / `_Extended::get_fields()`.
   This stops them appearing in the Settings page and stops `register_setting()` calls
   for them — which is correct: the new UI surface (Pipeline page) has its own
   nonce+capability+sanitize handler and does NOT use the WP Settings API.
3. **DO NOT delete the wp_option.** The underlying data key is unchanged.
   `uninstall.php` purges everything via `LIKE 'prautoblogger\_%'`.
4. **Add field definitions to** `PRAutoBlogger_Pipeline_Settings_Option_Fields_Data`
   under the appropriate context method.
5. **Document** the retirement in ARCHITECTURE.md #25 and CHANGELOG.md.
6. **Do not add `register_setting()`** in the Pipeline page — its save handler sanitizes
   directly, so WP Settings API registration is not needed.

Retired sections in M2: `prautoblogger_models`, `prautoblogger_content`, `prautoblogger_sources`.

## How To: Add a New Pipeline Stage

Writing stages are methods on `Content_Generator` that delegate to its `execute_stage()`
helper (Opik span + LLM dispatch + cost log + run-stage state in one place).

1. Add the stage method to `class-content-generator.php` and call
   `$this->execute_stage( $stage, $span_name, $user_prompt, $request, $model, $options )`
2. Wire it into `generate_multi_step()` (or the relevant mode path)
3. Add the stage to `PRAutoBlogger_Stage_Display_Map` (label, default agent role, prompt key)
   and — if it has registry-managed copy — a prompt key in the defaults classes
4. Cost logging and per-run stage state (idempotent resume, output snapshot) come free from
   `execute_stage()`; the per-run cost governor guards the call inside the provider
5. If a stage fails after retries, the entire pipeline fails (no partial publishes); the
   stage row is failed by the worker's catch-all and the run is swept by Run_Reaper

---

## How To: Add a New Frontend Component

Frontend components use `wp.element` (WordPress-bundled React) — no JSX, no build step.

1. Create the PHP class in `includes/frontend/class-{name}.php`:
   - Register a shortcode via `add_shortcode()` in an `on_register_shortcode()` method
   - Register a REST endpoint if the component needs async data (in `on_register_rest_route()`)
   - Enqueue JS (`wp-element` dependency) and CSS via `wp_enqueue_script/style()`
   - Pass config to JS via `wp_localize_script()`

2. Create JS in `assets/js/{name}.js`:
   - Use `wp.element.createElement` (aliased as `el`) for rendering
   - Import hooks: `var useState = wp.element.useState;`
   - Read config from `window.{localizedObjectName}`
   - Handle all states: loading, loaded, empty, error
   - Mount into the shortcode's `<div id="...">` mount point

3. Create CSS in `assets/css/{name}.css`:
   - Use Peptide Starter theme CSS custom properties with fallbacks: `var(--color-text-primary, #f9fafb)`
   - Prefix all classes with `prab-` to avoid collisions
   - Include responsive breakpoints, focus-visible states, and loading skeletons

4. Wire hooks in `class-prautoblogger.php`:
   - Instantiate the PHP class in `register_frontend_hooks()`
   - Register shortcode on `init`, REST routes on `rest_api_init`

5. Add `'frontend/'` to the autoloader's `$directories` array (already done for the first component)

6. Update ARCHITECTURE.md file tree and add a data flow section

### REST endpoint conventions:
- Namespace: `prautoblogger/v1`
- Permission callback: use a filter hook for extensibility (e.g., `prautoblogger_rest_{name}_public`)
- Sanitize all params with `sanitize_callback` in arg registration
- Return only the fields the frontend needs — no unnecessary data
- Set `Cache-Control` header for cacheable responses

---

## Code Quality Rules

1. **`declare(strict_types=1);`** in every PHP file
2. **Type declarations** on all method parameters, return types, and class properties
3. **No file exceeds 300 lines.** Split when approaching this limit
4. **Every class has a 3-question preamble docblock:** What / Who triggers / Dependencies
5. **Every public method has a docblock** with `@param`, `@return`, and side effects
6. **`@see` references** at the top of files that participate in multi-class flows
7. **All strings translatable** via `__()` / `_e()` with `prautoblogger` text domain
8. **No magic methods** without explicit justification documented in code
9. **No `echo` of raw data** — always escape with `esc_html()`, `esc_attr()`, etc.
10. **Nonce on every form/AJAX** — `wp_nonce_field()` / `check_ajax_referer()`


## How To: Add an OpenRouter model picker field (v0.11.0+)

Use the `model_select` field type to let admins choose OpenRouter models with a searchable dropdown, capability filtering, and estimated cost preview based on usage history.

### 1. Declare the field in `class-settings-fields.php`

```php
[
    'id'          => 'my_plugin_model',
    'label'       => __( 'My Model Setting', 'my-plugin' ),
    'type'        => 'model_select',
    'section'     => 'my_plugin_settings',
    'default'     => 'anthropic/claude-3.5-haiku',
    'capability'  => 'text→text',  // see ARCHITECTURE.md §18 for all capability strings
    'description' => __( 'Choose a model...', 'my-plugin' ),
    'badge'       => __( 'Quality', 'my-plugin' ),
],
```

### 2. Map the setting to pipeline stages (for cost preview)

If you want cost preview to work, add your setting ID and stage list to the `get_stages_for_setting()` method in `includes/admin/fields/class-open-router-model-field.php`:

```php
private static function get_stages_for_setting( string $field_id ): array {
    $map = [
        'prautoblogger_analysis_model'     => [ 'analysis' ],
        'prautoblogger_writing_model'      => [ 'outline', 'draft', 'polish' ],
        'prautoblogger_editor_model'       => [ 'review' ],
        'my_new_model_setting'             => [ 'my_stage' ],  // ADD HERE
    ];
    return $map[ $field_id ] ?? [];
}
```

The cost preview queries `get_avg_tokens_for_stages()` on the Cost Tracker to show estimated cost per generation based on the last 30 days of usage.

### 3. That's it

The field renderer, AJAX refresh, model picker JS, and capability filtering are already wired. The admin will see a "Refresh Model List" button on the AI Models tab (or whichever tab contains the picker field).

### Capability strings (locked in v0.11.0)

- `text→text` — text input, text output (default for analysis/writing/editor)
- `text+image→text` — vision models
- `text+audio→text` — audio input models
- `text→image` — image generation
- `text→audio` — voice synthesis
- `text→video` — video generation
- `text→embedding` — embeddings

Phase 3 will add provider selection; v0.11.0 is OpenRouter-only.

## How To: Change a Pipeline Prompt (v0.18.0+)

Prompt copy is **versioned data**, not code. The bodies live in
`wp_prautoblogger_prompts` (one ACTIVE version per key); the hardcoded texts in
`class-prompt-defaults.php` / `class-prompt-defaults-editorial.php` are the canonical v1 seed
AND the fallback when the table is unavailable — never edit a stored version in place.

1. **Never `UPDATE` a body.** Create a new version and activate it:
   ```php
   $v = PRAutoBlogger_Prompt_Registry_Writer::create_version(
       'content.polish', $new_body, $model, $params, 'your-name', true
   );
   ```
2. **Keys** are dot-namespaced: `content.system`, `content.single_pass`, `content.outline`,
   `content.draft`, `content.polish`, `analysis.system`, `analysis.user`, `editor.system`,
   `editor.review`, `research.system`, `image.rewriter_system`, `image.style_template`.
   The two `image.*` keys are the image-composer seam — coordinate on the convo thread
   before touching them.
3. **Tokens** use the `{{ name }}` syntax (exactly one space each side — same convention as
   the Style Template's `{{ topic_summary }}`). Token VALUES are computed by code
   (`Content_Prompts`, `Analysis_Prompts`, `Chief_Editor`); a new prompt version may reorder
   or drop tokens but must not invent new ones without adding the value at the call site.
4. **Pinning.** A run pins the active version of every key at start
   (`runs.pinned_prompts_json`, written by `Cost_Tracker::set_run_id()`); every
   generation_log row is stamped with the pinned `prompt_version`. Activating a new version
   affects the NEXT run, never a run in flight.
5. **Call sites** render via `PRAutoBlogger_Prompt_Registry::render( $key, $tokens )` and
   must keep working when the table is absent (the registry falls back to the defaults
   automatically — do not add your own fallback).
6. If you add a key: define the body in the appropriate defaults class, add it to `defs()`,
   map its stage in `Stage_Display_Map`, and it will seed on the next migration pass.

## Git Workflow — PR-Gated, Soft-Enforced

This repo is private on GitHub's free plan, which does not support branch protection or rulesets. The review gate is enforced at the agent layer, not server-side. Every agent and human contributor follows these rules; the `.github/workflows/main-push-audit.yml` tripwire opens an audit issue on any direct push to `main` that did not come from a merged PR.

### Rules

1. **Never push to `main` directly.** Every change lands via a pull request that the maintainer merges. No self-merging. No force-pushing to `main`.

2. **Branch naming**: `claude/<scope>-<YYYYMMDD>` for agent-authored work, `fix/<scope>` or `feat/<scope>` for human-authored. The scope is a 1–3 word kebab-case description.

3. **Commit trailer**: every commit authored by an agent must end with an `Agent-Session:` trailer so commits can be correlated back to the conversation that produced them, even though all commits share the `peptiderepo` bot identity.

   ```
   feat: add per-request cost tracking

   Agent-Session: cowork-2026-04-14-cost-audit
   ```

4. **PR description template**: every PR description covers
   - **What changed** (one paragraph)
   - **Why** (motivation or incident link)
   - **Risk flags** (schema changes, API contract changes, cost impact, compatibility)
   - **Test plan** (what was run locally, what to smoke-test after merge)

5. **Emergency push exception**: if a situation genuinely requires pushing to `main` without a PR (site down, CI broken, one-line hotfix), surface it in the chat before doing it. Every emergency push gets a follow-up PR that commits the same changes through the normal flow so git stays the source of truth. The tripwire will open an audit issue — close it with a comment explaining why.

### Opening a PR from an agent session

The `gh` CLI is not installed in the Cowork sandbox. Use `curl` with the PAT from the workspace `.env.credentials`:

```bash
GH_TOKEN=$(grep "^GITHUB_PAT=" "$WORKSPACE/.env.credentials" | cut -d= -f2)

git push -u origin HEAD

curl -s -X POST -H "Authorization: token $GH_TOKEN" \
  -H "Content-Type: application/json" \
  "https://api.github.com/repos/peptiderepo/<repo>/pulls" \
  -d "$(jq -cn --arg title "<title>" --arg head "<branch>" --arg body "<body>" \
      '{title:$title, head:$head, base:"main", body:$body}')"
```

### When this changes

If the peptiderepo GitHub account is ever upgraded to Pro (or the repo goes public), replace this soft-enforcement section with a note pointing to the real branch protection rules in repo settings.
## Instrumenting a New LLM Call Site for Opik (v0.12.0+)

When you add a new LLM call (via `$llm->send_chat_completion()`), follow this pattern to capture it in Opik:

### 1. Get the trace context

At the start of your method, grab the per-request singleton:

```php
$ctx = PRAutoBlogger_Opik_Trace_Context::current();
```

### 2. Start the span

Before the LLM call, declare a span with metadata:

```php
$span_id = $ctx->start_span(
	array(
		'name'     => 'my_llm_operation',  // kebab-case, descriptive
		'type'     => 'llm',
		'model'    => $model,               // e.g. 'gpt-4', from get_option()
		'provider' => 'openrouter',         // or the actual provider name
		'input'    => array( 'key' => 'value' ), // optional metadata (no full prompts!)
	)
);
```

### 3. Make the LLM call (unchanged)

```php
$response = $this->llm->send_chat_completion(
	array(
		array( 'role' => 'system', 'content' => $system_prompt ),
		array( 'role' => 'user', 'content' => $user_prompt ),
	),
	$model,
	array( 'temperature' => 0.7, 'max_tokens' => 4000 )
);
```

### 4. End the span with output

After the response, record the outcome:

```php
$ctx->end_span(
	$span_id,
	array(
		'output' => array( 'response_length' => strlen( $response['content'] ?? '' ) ),
		'usage'  => array(
			'prompt_tokens'     => $response['prompt_tokens'],
			'completion_tokens' => $response['completion_tokens'],
			'total_tokens'      => $response['prompt_tokens'] + $response['completion_tokens'],
		),
	)
);
```

### Key rules

- **Never log full prompts.** Use prompt hashes or version tags in `input`. Full text blows payload size and leaks content policy.
- **Check the feature flag at call-site level?** No. The singleton is always available; Opik_Dispatcher checks the flag before dispatch.
- **Async dispatch?** Yes. Spans are queued; dispatch happens via `prautoblogger_opik_dispatch` cron (no blocking).
- **Cost logging?** Unchanged. Your `cost_tracker->log_api_call()` remains separate from Opik; Opik is observability-only.

### Example: minimal instrumentation

```php
public function my_operation( $input ) {
	$model = get_option( 'my_model', 'default-model' );
	$ctx = PRAutoBlogger_Opik_Trace_Context::current();

	$span_id = $ctx->start_span(
		array(
			'name'     => 'my_operation',
			'type'     => 'llm',
			'model'    => $model,
			'provider' => 'openrouter',
		)
	);

	$response = $this->llm->send_chat_completion( /* ... */ );

	$ctx->end_span(
		$span_id,
		array(
			'usage' => array(
				'prompt_tokens'     => $response['prompt_tokens'],
				'completion_tokens' => $response['completion_tokens'],
				'total_tokens'      => $response['prompt_tokens'] + $response['completion_tokens'],
			),
		)
	);

	return $response['content'];
}
```

That's it. No config, no feature-flag checks, no try/catch needed.

---

## Hidden Admin Pages (v0.19.3 convention, codified after two incidents)

### Rule: use options.php as parent; NEVER unset/remove registered pages from $submenu

When a WordPress admin page must be accessible by URL but hidden from the nav menu, use the
**options.php-parent pattern** exclusively:

```php
add_submenu_page(
    'options.php',                     // parent: built-in WP page; pages hidden by construction
    __( 'My Page', 'prautoblogger' ),  // page title
    __( 'My Page', 'prautoblogger' ),  // menu title (never shown; options.php is hidden)
    'manage_options',                  // capability
    'my-page-slug',                    // page slug
    array( $this, 'render_page' ),     // callback
);
// No $submenu manipulation. options.php-parent pages are hidden from nav by construction.
```

The resulting hookname is `admin_page_my-page-slug` at BOTH registration time and request time.

### Why the hide-by-unset pattern causes 403 (the incident class)

WordPress computes the page hookname at two distinct moments:

**Registration time** (inside `add_submenu_page`):
WP resolves the parent slug against `$admin_page_hooks`. If found, hookname = `{parent_key}_page_{slug}`. WP registers the render callback and `$_registered_pages` entry under that name.

**Request time** (inside `wp-admin/admin.php`):
WP calls `get_admin_page_parent()` which scans `$submenu` for the page slug. If found, it derives the hookname the same way as (1). If **not found** (e.g., because the entry was unset), it falls back to `admin_page_{slug}`.

When these two hooknames diverge, WP dispatches the request to a hookname with no registered callback → `wp_die(403)`.

**Why options.php avoids this:** `options.php` is a built-in page that WordPress always keeps intact in `$admin_page_hooks` and `$submenu`. For its children, `get_admin_page_parent()` returns `options.php` consistently at both moments, so both produce `admin_page_{slug}`. No mutation required.

### Incident history

| Version | Symptom | Root cause | Fix |
|---------|---------|------------|-----|
| v0.19.1 | Board page 404 | Board submenu registered at same priority as parent (both 10) — add_submenu_page fired before add_menu_page → wrong hookname at registration time | Board moved to priority 11, after parent at 10 |
| v0.19.3 | Dossier page 403 | Dossier registered under `prautoblogger-settings` parent (priority 12, after parent ✓) but post-registration `unset($submenu[...])` removed the slug → get_admin_page_parent() returned false at request time → orphan `admin_page_*` hookname → no handler → 403 | Switched to `options.php` parent; removed all $submenu mutation |

Both incidents are in the same **hookname-mismatch class**: registration-time hookname ≠ request-time hookname.

### Test pattern

The `DossierMenuRegistrationTest::test_request_time_hookname_matches_registration_hookname` test
replicates the request-time resolution algorithm (scan `$submenu` for the slug; if found derive
hookname; if absent return `admin_page_*`) and asserts it equals the registration-time hookname.
This test FAILS on hide-by-unset implementations and passes on the options.php-parent pattern.
Apply the same test strategy to any new hidden admin page.

### Asset enqueue gate

For `options.php`-parent pages, the hook suffix passed to `admin_enqueue_scripts` is
`admin_page_{slug}`. For plugin-parent pages it would be `{parent_key}_page_{slug}`. Always
match the asset enqueue gate to the actual hookname for the chosen parent pattern:

```php
// options.php parent → 'admin_page_{slug}'
public function on_enqueue_assets( string $hook_suffix ): void {
    if ( $hook_suffix !== 'admin_page_' . self::PAGE_SLUG ) {
        return;
    }
    // ... enqueue
}
```

---

## How To: Add a New Pipeline-Style Admin Page

Use this pattern when a page needs per-step or multi-section rendering with
a separate save-handler (i.e. when the page is too complex for a single-class
approach). The Pipeline Settings page (`prautoblogger-pipeline`) is the
canonical reference.

### Four-class split

| Class | File | Responsibility |
|-------|------|---------------|
| `PRAutoBlogger_{Name}_Page` | `class-{name}-page.php` | Registration, asset enqueue, render entry-point. Delegates immediately. |
| `PRAutoBlogger_{Name}_Renderer` | `class-{name}-renderer.php` | Gathers ALL view data (options, spend, computed values). Passes a typed `$view` array to the template. No raw echo; no DB writes. |
| `PRAutoBlogger_{Name}_Save_Handler` | `class-{name}-save-handler.php` | Stateless. Verifies nonce + capability, sanitises input against an allowlist, persists. Returns `{status, message}`. Called before output starts. |
| `PRAutoBlogger_{Name}_Step_Map` | `class-{name}-step-map.php` | Single source of truth for step metadata and key allowlists. Read by both Renderer and Save_Handler. |

### Registration priority

The page submenu must register AFTER the parent menu. Convention:
- Parent menu: priority 10 (`PRAutoBlogger_Admin_Page`)
- Board: priority 11
- Dossier: priority 12
- Pipeline: priority 13
- Next pipeline-style page: priority 14+

### Renderer contract (non-negotiable)

The renderer must gather **all** data the template needs — including service
objects like `PRAutoBlogger_Cost_Reporter` — so the template receives only
plain scalars and arrays:

```php
// In Renderer::render():
$cost_reporter = new PRAutoBlogger_Cost_Reporter();
$view['monthly_spend'] = $cost_reporter->get_monthly_spend();
$view['budget']        = (float) get_option( 'prautoblogger_monthly_budget_usd', 50.00 );
```

**Never** instantiate service objects inside a template. The template must be
a logic-free view layer.

### Save-handler model-field POST key

`PRAutoBlogger_OpenRouter_Model_Field::render($id, ...)` emits:
```html
<input type="hidden" id="$id" name="$id" value="..." />
```
`model-picker.js` writes the selected model to that input **by DOM id**, so
the form posts the value under the option name, not a separate `model_id` key.
In the save handler, read:
```php
$model_id = isset( $_POST[ $option_name ] )
    ? sanitize_text_field( wp_unslash( $_POST[ $option_name ] ) )
    : '';
```
Do **not** read `$_POST['model_id']` — that key is never present.

### Nonce / capability pattern

```php
// 1. Check method (bail silently if not POST or nonce field absent).
if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) { return $idle; }
if ( empty( $_POST[ NONCE_FIELD ] ) )        { return $idle; }

// 2. Capability BEFORE nonce (avoids timing oracle for unauthenticated users).
if ( ! current_user_can( 'manage_options' ) ) { return error; }

// 3. Verify nonce.
if ( ! wp_verify_nonce( ... ) ) { return error; }
```

### Allowlist enforcement

All POST keys that map to DB writes (model option names, prompt keys) must be
validated against a static allowlist before use. Define the allowlist on
`Step_Map` and call it from the save handler:

```php
if ( ! in_array( $option_name, PRAutoBlogger_{Name}_Step_Map::allowed_model_options(), true ) ) {
    return array( 'status' => 'error', 'message' => __( 'Unknown model option.', 'prautoblogger' ) );
}
```

### Prompt key slug round-trip

Form fields carry dots as hyphens (`content.single_pass` → `content-single_pass`)
because HTML name attributes cannot contain dots in some contexts.
`sanitize_key()` preserves underscores, so the slug round-trip is unambiguous — no two
keys produce the same slug. Use `sanitize_key( str_replace( '.', '-', $key ) )` to compare,
not string parsing:

```php
private static function resolve_key_from_slug( string $slug ): ?string {
    foreach ( Step_Map::allowed_prompt_keys() as $key ) {
        if ( sanitize_key( str_replace( '.', '-', $key ) ) === $slug ) {
            return $key;
        }
    }
    return null;
}
```

### Tests

Every pipeline-style page must have PHPUnit coverage for:
- `Save_Handler`: allowlist enforcement (valid + invalid keys/options), correct
  option persistence, prompt versioning (`create_version()` called with correct
  key and `$activate = true`), `resolve_key_from_slug()` round-trip.
- `Step_Map`: `allowed_prompt_keys()` and `allowed_model_options()` regression
  guards so keys do not drift from the registry.

Place tests under `tests/unit/Admin/PipelineSettings*Test.php`, extending
`PRAutoBlogger\Tests\BaseTestCase`.

---

## Authority Pipeline — Phase 2b conventions (v0.28.0)

### Adding a new pipeline stage

Each stage is a self-contained class behind an interface. Pattern:

1. Create `includes/providers/interface-<stage>-<role>.php` (the contract).
2. Create `includes/core/class-<stage>-<role>.php` implementing it.
3. If the implementation grows past 200 lines of logic, extract helpers into a
   `class-<stage>-<helper>.php` — see `Research_Batch` (curl_multi layer) and
   `Research_Source_Scorer` (weighting math) as examples.
4. Register the stage in `Stage_Display_Map::MAP` if it is net-new vocabulary.
5. PHPUnit tests must cover: quorum/gating logic, cost-reserve call verification,
   invalid-output exclusion, graceful absent-table degradation.

### Research fan-out settings

| Option key | Default | Notes |
|---|---|---|
| `prautoblogger_research_agent_count` | 3 | Clamped 1–5. |
| `prautoblogger_research_model` | `PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL` | Per-run; read at call time. |

### Cost-reserve discipline for fan-out batches

Before dispatching N parallel calls, always reserve the SUMMED worst-case estimate:

```php
$per_estimate   = PRAutoBlogger_Cost_Governor::estimate_chat_cost( $model, $messages[0], $options );
$reservation    = PRAutoBlogger_Cost_Governor::open_amount_reservation(
    $per_estimate * $n,
    "stage_name:n={$n}:{$model}"
);
// ... dispatch ...
PRAutoBlogger_Cost_Governor::settle( $reservation, $actual_total );
```

Never reserve inside the per-agent loop — that creates a race between the ceiling
check and concurrent call dispatch.

---

## How To: Add a Bounded Editorial Loop Caller (P2b.2+)

The Authority-tier editorial pipeline uses a bounded editor<->writer loop
(`PRAutoBlogger_Editorial_Loop`) instead of the single-pass `Chief_Editor::review()`.

### Key patterns

- **Loop bound** is driven by `get_option('prautoblogger_editorial_max_rounds', 3)`,
  clamped to `[1, 10]`. Never hardcode round counts.
- **Escalation**: when max rounds are exhausted without approval, `run()` returns `''`
  and `was_escalated()` is `true`. The caller must handle this (save as draft to Review Queue).
- **Inline revision path**: if the editor's `PRAutoBlogger_Editorial_Review` object
  carries a non-null `revised_content`, use it directly without calling the writer LLM.
- **Writer revision**: when no inline content is available, delegate to
  `PRAutoBlogger_Editorial_Revision_Caller::call()`. Never put writer LLM logic inside
  `Editorial_Loop` itself -- keeps it under 300 lines.
- **Round recording**: every round (including escalation) writes one `run_decisions` row
  via `PRAutoBlogger_Audit_Writer::record_decision()`. Round rows carry `stage='editorial'`,
  `verdict='approved'|'revised'|'rejected'`, `rationale='Round N: <notes>'`. Escalation
  rows carry `verdict='escalated'`.
- **Stage lifecycle**: each editor check opens `run_stages` with `role='editor'` via
  `Run_Stage_State::start()`; each writer revision opens with `role='writer'`. Both close
  on `Run_Stage_State::done()` after their respective result is available.
- **Cost**: all LLM calls flow through the existing provider seam; cost governor reserves
  are handled per-call inside `Chief_Editor::review()` and `Editorial_Revision_Caller::call()`.
  No additional reservation needed in `Editorial_Loop::run()`.

### Splits to preserve the 300-line cap

| Class | Responsibility |
|---|---|
| `PRAutoBlogger_Editorial_Loop` | Loop orchestration, verdict routing, round recording, escalation |
| `PRAutoBlogger_Editorial_Revision_Caller` | Writer LLM call, stage lifecycle for 'writer' role |
| `PRAutoBlogger_Content_Prompts` | Static `build_revision_system()` + `build_revision_user()` |
| `PRAutoBlogger_Editorial_Round` | Immutable value object (one round snapshot) |

---

## Authority Pipeline — SEO Stage (P2b.3, v0.30.0)

### Design rules

The SEO stage is **deterministic** — no LLM calls, no external I/O beyond WordPress
post-meta writes and DB audit rows. It is the contract point between PRAB (writes
`_prab_*` meta) and prcore (reads meta to emit JSON-LD schema).

### Key patterns

- **No LLM calls**: `PRAutoBlogger_Seo_Stage::run()` is pure computation +
  meta-write. All data comes from arguments already produced by earlier stages.
- **citation_score**: `sum(quality_score) / count(kept_sources)`. Returns `0.0`
  when `$kept_sources` is empty — never divide-by-zero.
- **_prab_reviewed_by omitted**: the automated path never writes this key. Only
  P2b.4 (human Review Queue approval) sets it. Absence signals `editorial-system` mode.
- **Threshold is additive**: `prautoblogger_citation_score_threshold` is read and
  logged but the publish gate itself is in P2b.4. This stage stores the score only.
- **Stage lifecycle**: `Run_Stage_State::start('seo', 'seo', $item_key)` before writes;
  `::done()` after. Decision row via `Audit_Writer::record_decision()` with
  `stage='seo'`, `verdict='scored'`, `citation_score=$score`.
- **JSON values**: always use `wp_json_encode()`, never `json_encode()` directly.
- **Timestamps**: always use `gmdate('Y-m-d\TH:i:s')`, never `date()`.

### _prab_* key reference (ratified contract v1)

| Key | Type | Notes |
|---|---|---|
| `_prab_schema_version` | int `1` | Opt-in trigger — prcore emits JSON-LD only when present |
| `_prab_citations` | JSON string | `[{url, title, doi?, quality_score?}]` — kept sources only |
| `_prab_about_peptides` | JSON string | `[post_id, ...]` — related peptide IDs |
| `_prab_review_mode` | string | `editorial-system` (automated) or `human` (P2b.4) |
| `_prab_reviewed_at` | string | ISO 8601 datetime |
| `_prab_reviewed_by` | int | WP user ID — written only by P2b.4 on human approval |
| `_prab_citation_score` | string (float) | Avg quality_score of kept sources; publish gate in P2b.4 |

---

## Tier Routing + Master Flag Pattern (P2b.4, v0.31.0)

### Design rules

The master flag `prautoblogger_authority_pipeline_enabled` (default `false`) gates
the entire Authority pipeline. When OFF, `PRAutoBlogger_Tier_Router::resolve()` MUST
return `economy` unconditionally — no category-map reads, no secondary checks. This
guarantees zero behaviour change in production on deploy.

### Key patterns

- **Master flag check first**: always the first branch in `resolve()`. If false, return
  economy immediately without reading the category map.
- **Economy is the safe default**: if the category map is empty, absent, or corrupt,
  the tier defaults to authority (the additive default when flag is ON); but the flag
  being OFF is the primary safety gate.
- **Per-category demotion**: the `prautoblogger_category_tiers` option stores a
  serialized PHP array `[category_slug => economy]`. Only economy is a meaningful
  explicit value — other values fall through to the Authority default.
- **Article_Worker integration**: three-line check before the Economy path:
  ```php
  $tier = ( new PRAutoBlogger_Tier_Router() )->resolve( $idea );
  if ( authority === $tier ) {
      return ( new PRAutoBlogger_Authority_Pipeline( $cost_tracker ) )->run( $run_id, $idea, $cost_tracker );
  }
  ```
  The Economy path below the check is UNCHANGED — the branch is additive-only.
- **Imagery gate**: held articles (citation gate miss, escalation, or cost ceiling) get
  `_prautoblogger_imagery_suppressed = 1` post-meta. Image pipeline checks this key
  before running. Publish-gate-passed articles never have this key set.


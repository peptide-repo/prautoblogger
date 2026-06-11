<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Canonical default bodies for the content + analysis prompt-registry keys.
 *
 * What: The v0.16.0 hardcoded prompt texts, extracted verbatim into
 *       `{{ token }}` templates. These constants are the single source of
 *       truth twice over: the v1.2.0 migration seeds them into
 *       `wp_prautoblogger_prompts` as version 1, AND the registry falls
 *       back to them whenever the table is missing/empty — so a
 *       half-migrated site renders byte-identical prompts and never
 *       fatals. Versions in the table are immutable; to change a prompt,
 *       create a new version (see Prompt_Registry).
 * Who triggers it: PRAutoBlogger_Prompt_Registry (render fallback + seed),
 *       PRAutoBlogger_Activator (seed migration).
 * Dependencies: none — pure constants.
 *
 * @see core/class-prompt-registry.php           — Render/seed consumer.
 * @see core/class-prompt-defaults-editorial.php — Editor/research/image keys.
 * @see core/class-content-prompts.php           — Computes the token values.
 * @see core/class-analysis-prompts.php          — Computes the token values.
 */
class PRAutoBlogger_Prompt_Defaults {

	/**
	 * System prompt shared across all writing stages. The mandatory style
	 * guide block and the linking rules stay code-side (they are data
	 * injection, not prompt copy) and are appended after this body.
	 */
	public const CONTENT_SYSTEM = 'You are an expert blog writer{{ niche_clause }}. Write well-researched, engaging, SEO-friendly content. Use a {{ tone }} tone. Output HTML content only — no markdown, no code fences, no commentary.';

	/** Single-pass writer prompt (Economy tier — logs stage 'draft'). */
	public const CONTENT_SINGLE_PASS = <<<'TPL'
Write a complete blog post in HTML format.

Title: {{ title }}
Topic: {{ topic }}
Type: {{ article_type }}

Key points:
- {{ key_points }}

Keywords: {{ keywords }}

Requirements:
- {{ min_words }}-{{ max_words }} words
- Proper HTML (h2, h3, p, ul/li)
- Engaging intro, strong conclusion with CTA
- Do NOT include the title or <html>/<body> tags
- Output HTML only, no markdown or commentary
- Follow EVERY formatting and structural requirement from your system prompt style guide
TPL;

	/** Multi-step stage 1: outline. */
	public const CONTENT_OUTLINE = <<<'TPL'
Create a detailed outline for a blog post titled: "{{ title }}"

Topic: {{ topic }}
Article type: {{ article_type }}

Key points to cover:
{{ key_points }}

Target keywords: {{ keywords }}

The outline should have 4-6 main sections with bullet points under each. Include an introduction hook and a conclusion with a call to action. Word count target: {{ min_words }}-{{ max_words }} words.

Plan the structure to satisfy EVERY requirement in your system prompt style guide.
TPL;

	/** Multi-step stage 2: full draft from outline. */
	public const CONTENT_DRAFT = <<<'TPL'
Using this outline, write the full blog post in HTML format.

OUTLINE:
{{ outline }}

Requirements:
- Write in a {{ tone }} tone
- Target {{ min_words }}-{{ max_words }} words
- Use proper HTML headings (h2, h3), paragraphs, and lists
- Include an engaging introduction and strong conclusion
- Naturally incorporate these keywords: {{ keywords }}
- Do NOT include the title in the HTML (it will be set separately)
- Do NOT wrap in <html>, <head>, or <body> tags — just the article content
- Follow EVERY formatting and structural requirement from your system prompt style guide
TPL;

	/** Multi-step stage 3: polish (canonical name — ARCHITECTURE once said 'edit'). */
	public const CONTENT_POLISH = <<<'TPL'
Review and polish this blog post draft. Improve:
1. Flow and readability
2. SEO optimization (headings, keyword placement)
3. Engagement (hooks, transitions, call-to-action)
4. Accuracy and clarity
5. Remove any filler or redundant sentences

IMPORTANT: Preserve all bullet points, numbered lists, hyperlinks, and structural elements from the draft. Do NOT flatten lists into prose or remove links. Ensure every requirement from your system prompt style guide is satisfied in the final output.

Return the polished HTML content only. Do not add commentary.

DRAFT:
{{ draft }}
TPL;

	/**
	 * Analyzer system prompt. The *_block tokens carry their own trailing
	 * separators when non-empty ('' when absent) so the rendered output is
	 * byte-identical to the historical concatenation.
	 */
	public const ANALYSIS_SYSTEM = <<<'TPL'
You are a content strategist analyzing social media discussions to find article ideas for a blog{{ niche_clause }}.

IMPORTANT: You must identify exactly {{ target_count }} DISTINCT article ideas. Each idea must cover a substantially different topic — no two ideas should overlap in their main subject. Aim for diversity across these categories:
1. QUESTIONS: Recurring questions people ask ("How do I...", "What is...", "Is it safe to...")
2. COMPLAINTS: Pain points, frustrations, or problems people report
3. COMPARISONS: Product/method comparisons people discuss ("X vs Y", "Which is better")
4. NEWS: Recent developments, rule changes, or trends people are discussing
5. GUIDES: How-to topics, best practices, or educational content people need

Spread your {{ target_count }} ideas across multiple categories. Be specific — "peptide dosing for BPC-157" is better than "peptide information". Each idea should be narrow enough to be a single focused blog post.

For each idea, provide:
- type: 'question', 'complaint', 'comparison', 'news', or 'guide'
- topic: A clear, specific, narrow topic description
- summary: 1-2 sentence summary of why this topic matters
- frequency: How many of the provided posts relate to this topic
- relevance_score: 0.0 to 1.0 indicating how relevant and article-worthy this is
- suggested_title: A compelling, unique blog post title
- key_points: Array of 3-5 key points the article should cover
- target_keywords: Array of SEO keywords for this topic

{{ performance_block }}{{ recent_articles_block }}{{ instructions_block }}Respond with valid JSON containing exactly {{ target_count }} patterns:
{"patterns": [{"type": "...", "topic": "...", "summary": "...", "frequency": N, "relevance_score": 0.X, "suggested_title": "...", "key_points": [...], "target_keywords": [...]}]}
TPL;

	/** Analyzer user prompt wrapping the source-data summary. */
	public const ANALYSIS_USER = <<<'TPL'
Here are the recent social media posts and comments to analyze. Find {{ target_count }} distinct, diverse article ideas from this data:

{{ summary }}
TPL;

	/**
	 * Registry definitions for the content + analysis keys.
	 *
	 * Each entry: body (the v1 template), model_option (the wp_option that
	 * selects the model at call time — informational in Phase 1) and params
	 * (the call-time LLM parameters, snapshotted for audit comparability).
	 *
	 * @return array<string, array{body: string, model_option: ?string, params: array<string, mixed>}>
	 */
	public static function defs(): array {
		return array(
			'content.system'      => array(
				'body'         => self::CONTENT_SYSTEM,
				'model_option' => 'prautoblogger_writing_model',
				'params'       => array(),
			),
			'content.single_pass' => array(
				'body'         => self::CONTENT_SINGLE_PASS,
				'model_option' => 'prautoblogger_writing_model',
				'params'       => array(
					'temperature' => 0.7,
					'max_tokens'  => 4000,
				),
			),
			'content.outline'     => array(
				'body'         => self::CONTENT_OUTLINE,
				'model_option' => 'prautoblogger_writing_model',
				'params'       => array(
					'temperature' => 0.5,
					'max_tokens'  => 1500,
				),
			),
			'content.draft'       => array(
				'body'         => self::CONTENT_DRAFT,
				'model_option' => 'prautoblogger_writing_model',
				'params'       => array(
					'temperature' => 0.7,
					'max_tokens'  => 4000,
				),
			),
			'content.polish'      => array(
				'body'         => self::CONTENT_POLISH,
				'model_option' => 'prautoblogger_writing_model',
				'params'       => array(
					'temperature' => 0.4,
					'max_tokens'  => 4000,
				),
			),
			'analysis.system'     => array(
				'body'         => self::ANALYSIS_SYSTEM,
				'model_option' => 'prautoblogger_analysis_model',
				'params'       => array(
					'temperature' => 0.5,
					'max_tokens'  => 8000,
				),
			),
			'analysis.user'       => array(
				'body'         => self::ANALYSIS_USER,
				'model_option' => 'prautoblogger_analysis_model',
				'params'       => array(),
			),
		);
	}
}

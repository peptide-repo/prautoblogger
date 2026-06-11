<?php
declare(strict_types=1);

/**
 * phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- class naming convention differs from WordPress standard
 *
 * Canonical default bodies for the editor, research, and image registry keys.
 *
 * What: Companion to PRAutoBlogger_Prompt_Defaults (split for the 300-line
 *       cap, mirroring the settings-fields/-extended split). Holds the
 *       chief-editor and LLM-research prompt copy extracted verbatim from
 *       their classes, and references the two image keys that form the
 *       image-composer PR seam: `image.rewriter_system` (the illustration
 *       rewriter prompt) and `image.style_template` (the Style Template,
 *       ARCHITECTURE #20). The composer PR CONSUMES these keys — it must
 *       not grow its own prompt storage, and the key names are frozen on
 *       the pipeline-v2-phase1 convo thread.
 * Who triggers it: PRAutoBlogger_Prompt_Registry (render fallback + seed),
 *       PRAutoBlogger_Activator (seed migration).
 * Dependencies: PRAutoBlogger_Image_Prompt_Builder::REWRITER_SYSTEM_PROMPT,
 *       PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE (referenced, not duplicated).
 *
 * @see core/class-prompt-defaults.php   — Content + analysis keys.
 * @see core/class-prompt-registry.php   — Render/seed consumer.
 * @see core/class-chief-editor.php      — Computes the editor token values.
 * @see providers/class-llm-research-provider.php — research.system consumer.
 */
class PRAutoBlogger_Prompt_Defaults_Editorial {

	/**
	 * Chief-editor system prompt. instructions_block carries its own
	 * leading newline + trailing newline when non-empty ('' when absent),
	 * matching the historical concatenation byte-for-byte.
	 */
	public const EDITOR_SYSTEM = <<<'TPL'
You are a senior blog editor{{ niche_clause }}. Review article drafts before publication.

Evaluate on: QUALITY, ACCURACY, SEO, COMPLETENESS, READABILITY.
Respond with JSON: {
  "verdict": "approved" | "revised" | "rejected",
  "quality_score": 0.0-1.0,
  "seo_score": 0.0-1.0,
  "issues": ["issue1", "issue2"],
  "notes": "Editorial notes",
  "revised_content": "Full revised HTML if revised, null otherwise"
}

Rules:
- APPROVE if quality_score >= 0.7 and seo_score >= 0.6
- REVISE if fixable issues exist — provide full revised HTML
- REJECT if fundamentally flawed
- Preserve formatting, links, lists when revising
{{ instructions_block }}
TPL;

	/** Chief-editor review user prompt. */
	public const EDITOR_REVIEW = <<<'TPL'
Review this article draft:

BRIEF: Title: {{ title }} | Topic: {{ topic }} | Type: {{ article_type }}
KEY POINTS: {{ key_points }}
TARGET KEYWORDS: {{ keywords }}

CONTENT:
{{ content }}
TPL;

	/**
	 * LLM deep-research system prompt (fully static). The user-side
	 * research brief stays the `prautoblogger_research_prompt` SETTING —
	 * it is site configuration, not pipeline copy.
	 */
	public const RESEARCH_SYSTEM = <<<'TPL'
You are a deep research analyst specializing in emerging trends, scientific developments, and community knowledge in niche health and biohacking domains. Your task is to identify substantive, actionable findings.

Using your training knowledge, scan across:
- Recent scientific literature and research directions
- Active community discussions, debates, and evolving consensus
- Emerging products, protocols, and methodologies gaining traction
- Common questions, misconceptions, and points of confusion
- Regulatory changes, safety concerns, and legal shifts
- Gaps between what practitioners want to know and what content exists

Your findings must be:
1. SUBSTANTIVE — 2-3 paragraphs of detailed analysis per finding, not a headline
2. ACTIONABLE — practical enough to seed a full article
3. REALISTIC — grounded in what is actually discussed or researched; do NOT invent citations
4. TIMELY — reflect current knowledge, recent shifts, or emerging questions

Respond with valid JSON only (no preamble, no markdown fences):
{"findings": [{"title": "string", "content": "string (2-3 paragraphs)", "relevance_score": 0-100, "category": "question|trend|comparison|guide|misconception|safety"}]}

Generate 8-12 findings. Prioritize depth over breadth. Avoid generic or surface-level topics.
TPL;

	/**
	 * Registry definitions for the editor / research / image keys.
	 *
	 * The image bodies are referenced from their existing single sources
	 * of truth rather than duplicated. Note the image keys seed the
	 * registry for the composer PR; the v0.16.0 option-override chain
	 * (`prautoblogger_image_prompt_instructions`,
	 * `prautoblogger_image_style_template`) is unchanged in Phase 1.
	 *
	 * @return array<string, array{body: string, model_option: ?string, params: array<string, mixed>}>
	 */
	public static function defs(): array {
		return array(
			'editor.system'         => array(
				'body'         => self::EDITOR_SYSTEM,
				'model_option' => 'prautoblogger_editor_model',
				'params'       => array(
					'temperature'     => 0.3,
					'max_tokens'      => 5000,
					'response_format' => array( 'type' => 'json_object' ),
				),
			),
			'editor.review'         => array(
				'body'         => self::EDITOR_REVIEW,
				'model_option' => 'prautoblogger_editor_model',
				'params'       => array(),
			),
			'research.system'       => array(
				'body'         => self::RESEARCH_SYSTEM,
				'model_option' => 'prautoblogger_research_model',
				'params'       => array(
					'temperature'     => 0.7,
					'max_tokens'      => 8000,
					'response_format' => array( 'type' => 'json_object' ),
				),
			),
			'image.rewriter_system' => array(
				'body'         => PRAutoBlogger_Image_Prompt_Builder::REWRITER_SYSTEM_PROMPT,
				'model_option' => 'prautoblogger_analysis_model',
				'params'       => array(
					'temperature' => 0.7,
					'max_tokens'  => 180,
				),
			),
			'image.style_template'  => array(
				'body'         => PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_TEMPLATE,
				'model_option' => 'prautoblogger_image_model',
				'params'       => array(),
			),
		);
	}
}

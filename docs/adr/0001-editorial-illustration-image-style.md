# ADR-0001: Editorial illustration image style — pivot from comic to text-free illustration with caption-as-HTML

- **Status:** Accepted
- **Date:** 2026-05-29
- **Deciders:** CTO, Engineer PRAutoBlogger
- **Supersedes:** —
- **Superseded by:** —
- **Related:** ARCHITECTURE.md §#20; CHANGELOG.md [0.16.0]; PR #146 (d00e746);
  `docs/proposals/2026-05-29-image-pipeline-in-plugin-brief.md`;
  ARCHITECTURE.md §#21 (v0.17.0 deterministic composer, the follow-on commit)

---

## Context

PRAutoBlogger generates two AI images (A/B pair) for each article and publishes
them as featured images. From the initial comic-strip launch (commit b17ed47,
`feat: switch to single-panel newspaper comic style for article images`) through
v0.15.x, the image style was a **single-panel newspaper comic** — the rewriter
LLM was asked to produce a comic gag or visual pun, and the result was appended
to a style suffix and sent to the diffusion model.

By April 2026 two problems had accumulated:

1. **Text legibility.** Small diffusion models (FLUX.1 schnell is the default
   since v0.9.0 at ~$0.0006/image) cannot reliably render readable text inside
   an image. Comic panels depend on legible speech bubbles or captions burned
   into the image; both were routinely garbled (confirmed in the 2026-04-21 A/B
   finding documented in ARCHITECTURE.md §#21).

2. **Caption path already existed.** A separate HTML caption below the image had
   been extracted as early as commit 214f57b (`Separate comic captions from
   generated images`, 2026-04-18). The scene/caption split in
   `Image_Scene_Parser` was already live, meaning there was an established path
   for readable text that did not depend on the diffusion model rendering it.

Together these created pressure to stop asking the diffusion model to render
text at all, and instead shift all readable copy to the HTML caption path that
was already in place.

## Decision drivers

1. **Image quality on default model.** FLUX.1 schnell (the default since v0.9.0)
   produces garbled text in generated images. A style that depends on readable
   in-image text degrades silently on the model we actually ship.
2. **Editorial trust signal.** Peptide Repo's editorial direction is
   science-informational; comic gags undercut the authority the site needs for
   health-adjacent content.
3. **Reuse the existing caption path.** The scene/caption split and HTML caption
   path were already live; the change is a prompt rewrite + template substitution
   with no new infrastructure.
4. **Admin configurability.** The style suffix was a single string appended to
   every prompt; a template with a named substitution token (`{{ topic_summary
   }}`) is more maintainable and admin-overridable without a code deploy.
5. **Cost and provider neutrality.** The change is prompt-only; provider, model,
   and pricing are unchanged.

## Options considered

### Option A — Keep the comic style, fix text rendering per-model

Ask the rewriter LLM to omit text from comic panels (no speech bubbles, no
caption burns), and describe a visual scene instead. Keep the style suffix
pattern.

**Pros:**
- Minimal code change — just a prompt edit.
- Comic aesthetic is recognisable and differentiating.

**Cons:**
- Does not solve the underlying mismatch: the style suffix is a static string
  that gives no per-article context to the diffusion model. The rewriter scene
  still has no slot to insert topic-specific imagery.
- Comic gags require the LLM to invent a joke per peptide, which is inconsistent
  in tone.
- Comic aesthetic conflicts with the editorial trust direction for health content.

### Option B — Pivot to text-free editorial scientific illustration + template substitution (chosen)

Change the rewriter system prompt so the LLM emits a concise 1-2 sentence
topic/mechanism summary as the SCENE — one concrete centered focal subject, no
text, no people-as-gag, no logos. Replace the style suffix with an editable
template (`prautoblogger_image_style_template`) that contains a single
`{{ topic_summary }}` token filled per-article by
`PRAutoBlogger_Image_Template_Filler`. The CAPTION line from the scene/caption
split continues to flow to the HTML-caption-below-image path unchanged.

**Pros:**
- Diffusion model is never asked to render text; garbled-text failures are
  eliminated.
- The editorial scientific illustration style is consistent with the site's
  authority positioning.
- Template substitution gives admins a single editable field to control the
  entire visual style without touching the per-article rewriter logic.
- Degradation is explicit: missing token → append + warning; empty summary →
  style-only prompt; a blank prompt is never sent to the provider.
- Provider, model, and cost are unchanged.

**Cons:**
- Breaks the comic gag aesthetic for any site running PRAutoBlogger in a
  context where that was intentional (low risk for this deployment).
- The old `prautoblogger_image_style_suffix` option must be migrated
  (handled via one-time migration `prautoblogger_migrated_style_template_v0160`
  and mirroring to `_deprecated` for one cycle).

### Option C — Generate text overlays in PHP (deterministic composer only)

Skip the style change; solve legibility by compositing text onto images in PHP
after the diffusion model runs.

**Pros:**
- Diffusion model input is unchanged (comic style preserved).
- Composited text is always readable regardless of model.

**Cons:**
- Compositing a comic-style gag is much harder than compositing a caption band
  (arbitrary layout vs. a fixed panel at the bottom).
- Does not address the editorial direction mismatch.
- The deterministic PHP composer (ARCHITECTURE.md §#21) was already planned as
  a follow-on commit specifically for OG/social variants — pursuing it as the
  sole fix conflates two separate problems.

## Decision

**Option B** — pivot to text-free editorial scientific illustration with
caption-as-HTML and template substitution.

This was shipped as v0.16.0 (2026-05-29, PR #146, commit d00e746). The changes
are entirely in the prompt layer:

- `Image_Prompt_Builder::REWRITER_SYSTEM_PROMPT` now asks for a concise
  topic/mechanism SCENE and keeps the CAPTION line for the HTML path.
- `PRAutoBlogger_Image_Template_Filler::fill()` replaces the old
  `trim($scene . ' ' . $style_suffix)` concat at all three entry points
  (`build_article_prompt`, `build_source_prompt`, `build_fallback_prompt`).
- New admin setting `prautoblogger_image_style_template` with
  single-token validation replaces the Style Suffix field.
- One-time migration preserves the old suffix value for one cycle.

The deterministic PHP composer for OG/social branding (ARCHITECTURE.md §#21)
was deliberately deferred to a follow-on commit (v0.17.0, #152) so that the
prompt-only change could ship and be verified first.

## Consequences

**Accepted benefits:**
- Garbled-text image failures are eliminated on the default FLUX.1 schnell model.
- The editorial scientific illustration style is consistent with the site's
  health-content positioning and sustainable across future model changes.
- The template + token pattern makes style updates admin-configurable without
  code changes.

**Accepted costs:**
- The comic aesthetic is gone and cannot be restored by toggling a setting;
  switching back requires a prompt and template change.
- The `prautoblogger_image_style_suffix` option is deprecated; the migration
  runs once on upgrade and the old option is not deleted until a future cycle.

**Follow-up created:**
- ARCHITECTURE.md §#21 (v0.17.0): deterministic PHP image composer to composite
  branded OG and square card variants — necessary because the social/OG image
  travels without the HTML caption and therefore needs text burned in by PHP,
  not by the diffusion model.

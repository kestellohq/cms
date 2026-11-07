---
name: admin-ui-design
description: The design rules for Kestello's admin (core screens and plugin admin pages): tokens, density, the @kestello/ui components, the hard bans and accessibility. Load it before building or changing any admin screen, component or admin CSS, and when reviewing UI work.
---

# Admin UI design

The admin is an operational tool used daily by people who are not technical: a client running their own site, with developers and agencies setting it up. Precision and restraint beat decoration. Plugins render inside the same shell, so every pattern must also work for content core doesn't control.

## Principles

1. **Client first, depth on demand.** Plain language and calm surfaces by default; technical detail is one click away, never in the way.
2. **Honest status.** Show the real state of the site (health, jobs, scheduler); never decorate with invented data.
3. **Extensible by construction.** Every pattern a core screen uses must work for a plugin's page too.
4. **Light on the server.** No web-font CDNs, no large images required.

## Sources of truth

- **Tokens and components:** `core/resources/assets/css/admin-ui.css` (all `--k-*` custom properties and `k-*` classes). Its header and `:root` block are the reference.
- **Brand:** `core/resources/assets/brand/` holds `tokens.css` and the logos. Navy `#111827`, warm white `#F8F7F3`, accent `#6676D8`. Never stretch, recolour, outline, shadow or put a gradient on the logo, and keep clear space around it equal to the height of the mark's inner square.

## Tokens and density

- Colour: `--k-paper` (ground), `--k-sheet` / `--k-sheet-2` (surfaces, hover), `--k-ink` / `--k-ink-2` / `--k-ink-3` (text levels), `--k-rule` / `--k-rule-soft` (panel and row rules), `--k-field` (control borders), `--k-mark` / `--k-mark-soft` (selection and focus), `--k-focus`, status pairs `--k-success`, `--k-warning`, `--k-danger`, `--k-info` with `-soft` grounds, `--k-sched` (scheduled) and `--k-now`.
- Density is compact: `--k-h: 30px` controls (`--k-h-sm` 26px, `--k-h-lg` 36px), `--k-pad: 24px` gutter, `--k-radius: 3px`, `--k-heavy: 1px`.
- Type: `--k-font` (system sans), `--k-mono` only for machine values (codes, counts, keys, paths), never for prose or labels.
- Motion: `--k-ease`; honour `prefers-reduced-motion`.
- Restyle only by overriding `--k-*` on `:root` or `[data-theme]`, and always supply the light and the dark value (`:root[data-theme="dark"]` and the `prefers-color-scheme` block).

## Components

Build from `@kestello/ui` (`core/resources/js/ui/index.js`) before writing new markup:

`KAlert`, `KBadge`, `KButton`, `KCard`, `KDialogs`, `KEmpty`, `KField`, `KIcon`, `KInput`, `KKeyValue`, `KModal`, `KPageHeader`, `KSelect`, `KSpinner`, `KStat`, `KStatusBadge`, `KSwitch`, `KTable`, `KTabs`, `KTime`, `KWhen`, `KToasts`, `KSchemaForm`, `KRevision`, `KExtension`, `KSlot`, `KAvatar`, `KAvatarCore`, `KContentField`, `KPicker`, `KRichText`, `KTermSelect`, `KMediaGrid`, `KUploadZone`, `KMediaPicker`, `KBlockList`, `KFinder`, `KBuilder`, `KBlockSettings`.

- Settings-like forms: `KSchemaForm` from an API schema, not hand-built fields.
- Lay out cells with `.k-cells`; use utility classes that exist (`k-muted`, `k-small`, `k-nowrap`, `k-inline-field`, `k-sr-only`) instead of inline `style=""`.
- Icons come from the bundled Phosphor set through `KIcon`.
- `KRevision` marks the single most important thing on a screen, at most once.
- See [admin-page](../admin-page/SKILL.md) for wiring a page, its menu entry and its data.

## Hard rules

- **No CDN fonts** or third-party assets on shipped pages. System fonts or self-hosted files only.
- **No eyebrow labels** (small uppercase kickers above headings). Uppercase small type is for table heads and nav group headings only.
- **No emoji**, and no glyph characters as icons.
- **No coloured `border-left` wider than 1px** (no "callout stripe" cards).
- **Avoid placeholder text.** A field gets a visible label and, when needed, help text below. Placeholders are not labels and disappear on input.
- **No hard separators.** Hierarchy comes from spacing, type weight and soft 1px rules in `--k-rule` / `--k-rule-soft`, not thick black (or cream, in dark mode) lines. Heavy distinctions read as noise.
- **No shadows on surfaces at rest**, and no rounded, shadowed cards inside cards. `--k-shadow-lg` is for floating layers only (popovers, modals, toasts).
- **Accent means attention.** `--k-mark` / `--k-accent` mark selection, focus and "look here"; never decoration, headings or large fills.
- **Never fake data.** Empty states use `KEmpty` and say what to do next; no invented numbers or testimonials.
- The brand name is **Kestello**; the former name must never appear.

## Words

- Labels are short, plain and exact, in sentence case: "Page order", "Shown as", "Role on the page". Buttons say what they do ("Replace them", "Add after them"), not "OK".
- Docs quote these labels in bold, so renaming a label means updating the docs (see [docs-writing](../docs-writing/SKILL.md)).
- All text goes through `__()` (see [i18n](../i18n/SKILL.md)).

## Accessibility (WCAG 2.1 AA)

- Every input has a label (`KField` with `label`, or a `<label for>`); icon-only buttons have `aria-label`.
- Visible focus on everything focusable (the kit's `--k-focus` ring); never remove outlines without a replacement.
- Full keyboard use: dialogs trap focus and close on Escape, menus and tabs follow arrow keys.
- Text contrast at least 4.5:1 in both themes; status is never shown by colour alone.
- Live updates (saving, errors) are announced: `role="status"` or the toast region.
- Check light and dark themes, and widths down to phone size, before calling it done.

## Done when

- [ ] Built from `@kestello/ui` and existing `k-*` classes; no inline styles; no new colours outside `--k-*` tokens.
- [ ] None of the hard rules is broken.
- [ ] Looked at in the browser, in light and dark, at desktop and phone widths.
- [ ] Every input labelled, every action reachable by keyboard, every string translatable.

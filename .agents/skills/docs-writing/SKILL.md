---
name: docs-writing
description: How to write Kestello's user and developer documentation in the separate kestellohq/docs repository (VitePress), following its STYLE.md. Load it whenever a change is visible to site owners, editors or developers, or when editing any docs page.
---

# Writing the docs

The docs live in their own repository, kestellohq/docs, checked out next to this one (`../docs`). It is a VitePress site. Its `STYLE.md` is the rulebook: read it fully before writing; this is the working summary.

## Every visible change gets a docs pull request

A change a site owner, editor or developer would notice (a new screen, a renamed label, a new setting, command, hook or endpoint, a changed default) comes with a pull request in kestellohq/docs. Link the two pull requests to each other. A renamed admin label means searching the docs for the old one.

## Two audiences, two sections

- **`guide/`** is for site owners and editors. They know a browser and an FTP client or hosting panel, nothing else. Explain each term the first time it appears on a page. Never ask them to read code.
- **`developers/`** is for people who write PHP and Vue. Assume PHP 8.4 and basic Vue. Show real code that runs.

The sidebars are separate (`.vitepress/config.mts`: the `guide` and `developers` arrays, wired as `sidebar: { '/guide/': guide, '/developers/': developers }`). A new page is added to the right array, in the order a reader meets it.

## Voice

- Write to the reader as "you", about the product as "Kestello".
- Say what to do, then why. One idea per sentence. Short, common words ("use", "start", "help").
- Quote admin labels exactly, in bold, with arrows for paths: **Settings → General**, **Upload a plugin**. Check the label in the running admin (or the code) first.
- Banned words: seamless, robust, leverage, powerful, effortless, cutting-edge, delve, unlock, elevate, streamline, game-changer, simply, just, easy, obviously.
- No em dashes and no en dashes. Use a comma, a colon, parentheses or a new sentence. Ranges use "to": "PHP 8.4 to 8.5".
- No exclamation marks, no emoji, no rhetorical questions as headings.
- Don't promise what isn't built: say "not available yet" and stop.

## Structure of a page

1. A first paragraph: what the page covers and when the reader needs it.
2. The task or concept, in the order the reader meets it.
3. Numbered steps for anything the reader does, one action per step, with what they should see when it helps.
4. "If something goes wrong": the real errors and what each means.
5. Links to the next page they are likely to need.

Headings are sentence case ("Restore a backup") and say what the section does.

## Facts

- Every command, setting, path, label and error message comes from the code or the running system. Run it before writing it down.
- Config keys in full: `app.packages.trusted_keys`.
- Paths relative to the site root: `config/app.php`, `content/plugins/`.
- Say where behaviour differs between shared hosting, a VPS and Docker, in the step where it matters.

## Formatting

- Commands in `bash` blocks with no `$` prompt; output in a separate `text` block.
- Tables for reference (settings, commands, fields), never for steps.
- `::: tip` for a shortcut, `::: warning` for something that can lose data or lock someone out, `::: danger` only for the irreversible.

## Checking your page

```bash
cd ../docs
npm ci
npm run build      # VitePress fails on dead internal links
npm run dev        # read it in the browser
grep -rn '[–—]' guide developers index.md                     # no en or em dashes
grep -rniwE 'simply|just|easy|obviously|seamless|robust|leverage|powerful' guide developers
```

## Done when

- [ ] The page is in the right section and listed in the sidebar.
- [ ] Every label, command, path and message was checked against the code or the running admin.
- [ ] No dashes, banned words, emoji or exclamation marks; headings in sentence case.
- [ ] `npm run build` passes in the docs repository.
- [ ] The docs pull request links the code pull request.

See also [admin-ui-design](../admin-ui-design/SKILL.md) for how labels are chosen, and [git-workflow](../git-workflow/SKILL.md) for commits (the docs repository follows the same conventions).

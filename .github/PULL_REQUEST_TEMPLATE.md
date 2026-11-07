<!--
Title: a Conventional Commit summary, for example "fix(media): keep the alt text when an image is replaced".
It becomes the commit message when this is squash-merged. See CONTRIBUTING.md.
-->

## What and why

<!-- What changes for site owners, editors or developers, and why. Link the issue: "Fixes #123". -->

## How it was tested

<!-- The tests you added, and what you checked by hand. Screenshots or a recording for anything visible, in light and dark. -->

## Checklist

- [ ] Tests cover the change (a fix starts with a test that failed without it).
- [ ] `npm run check` passes locally.
- [ ] Text people see uses `__()`, and `npm run i18n` was run.
- [ ] Vue and JavaScript changes are rebuilt with `npm run build`.
- [ ] `CHANGELOG.md` has an entry under Unreleased, or this has the `no changelog` label.
- [ ] Breaking changes are marked with `!` and explained, with migration notes.
- [ ] The docs change is in kestellohq/docs, or none is needed.

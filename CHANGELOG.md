# Changelog

Every change people would notice is listed here, newest first. The format follows [Keep a Changelog 1.1](https://keepachangelog.com/en/1.1.0/), and Kestello uses [Semantic Versioning](https://semver.org/). How to write an entry: [CONTRIBUTING.md](CONTRIBUTING.md#the-changelog).

## [Unreleased]

### Added

- The first public version of Kestello: structured content with revisions and scheduling, the visual page editor with blocks, patterns and per-screen styles, the template builder, media library, forms, menus, redirects, search, webhooks, a REST API with an explorer, people, roles and two-factor sign-in, backups, Site Health, background jobs and scheduled tasks, themes and plugins with signed packages, and signed core updates.
- A site language in **Settings > General**. The admin, emails and theme text follow it, and the admin formats plurals, numbers and dates for it.
- Translation templates: `bin/cms i18n:extract` writes the `.pot` file for core, a plugin or a theme.
- Updates from GitHub: Kestello checks the releases of `kestellohq/cms` twice a day (or on **Check now** under **System > Updates**), shows the release notes, and downloads, verifies and applies a new version from there. `bin/cms core:check` does the same check from the command line.
- **System > Security**: two-step sign-in coverage for the roles expected to use it, failed sign-ins (counts, top addresses, the latest), everyone's signed-in sessions and access tokens with **Sign out** and **Revoke**, and recent account events. A new capability, `security.manage`, and token scope, `security`, guard it.
- Editor helpers: `.phpstorm.meta.php`, `jsconfig.json`, and `bin/cms ide:helpers --stubs` for plugins and themes developed in their own repositories.
- Two-way relationships: an entry linked from others shows a **Linked from** card in the editor, the **Entry list** block can show the entries that link to the entry being viewed (or the ones it links to), and the content API answers `GET /content/{type}/entries/{id}/linked-from` and filters lists with `links_to`. `bin/cms content:relations` records the links of entries saved before.
- Backups of sites with more than 4 GB of files, or more than 65,535 files, no longer fail: archives switch to ZIP64 where they need it, and Kestello reads ZIP64 archives.
- Two people saving entries with the same title at the same moment can no longer both get the same address.
- Validation messages from the API, and the problems visitors see when sending a form (a missing answer, an expired page, a file that is too large), are in the site's language.
- A form's default button text, thank-you and closed messages follow the site's language, also for forms saved before it changed.
- Site pages load the active plugins' and theme's translations for the site language (also during server-side rendering), and the base theme's text is translatable. Site pages also get `auth.user.admin`, so a theme can offer a way to the admin to everyone who may open it.
- The **Roles** screen marks capabilities that amount to administrator access (people, roles, security, infrastructure, themes, plugins, updates, backups, site-wide code) and warns before a role gets one. Plugins flag theirs with `capability(..., sensitive: true)`.
- Image editing in the media library: **Edit image** crops (freely or to a fixed shape), turns and flips a picture. Edits always start from the original, which is kept private while the image is edited and comes back with **Restore original**. The focal point moves with the picture, and every size is made again. API: `POST /media/{id}/edit` and `POST /media/{id}/restore`. Image engines can support turning and flipping through the new `TransformableImage` contract.
- Form notification emails, and their default subject, are in the site's language.
- Error pages speak plainly and in the site's language ("Page not found", "Go to the home page"), in the theme's colours. A theme's own `errors/Error` page gets `status`, `heading`, `message` and `home` already translated.
- A mistyped address under `/admin` shows the admin's own **Page not found**, with the menu, instead of the site's.
- Menu items in the editor say what they are (Page, Post, Category) instead of "Page or post".
- Undo in the visual editor selects again the block that was selected before the step.
- Search pickers (**Parent**, **Author**, menu items) are no longer cut off inside cards and panels; near the bottom of the screen they open upwards.
- Interface fixes: the form builder's page and the submissions inbox fill the screen, buttons beside a field line up with its box, empty states space their button, sidebar groups such as **Search and sharing** match the sections around them, and address fields no longer get password manager icons.
- Leaving an editor with unsaved work asks first, also from the menu, a link or Back: entries, the visual editor, menus, templates, styles and forms. The form builder shows **Unsaved changes**. Plugins get the same with `useLeaveGuard()` from `@kestello/sdk`.
- A form's file field says its limits in the site's language ("Up to 3 files, 12 MB each."), never "undefined" in the form builder, and looks like the form's other fields. **Largest file** and **Most files** say what empty means, and turning on **Allow several files** after saving really allows several.
- File sizes in the admin are exact ("20 B" instead of "1 KB") and use the admin language's decimal mark.
- Reasons a save is refused (a redirect loop, a menu too deep, a form field without a type, and about a hundred more) are in the admin's language.
- Empty tables show a short title with the explanation under it, like the admin's other empty states.
- Backups with files include what visitors attached to forms (`storage/forms`), so a restore brings back submissions with their files. Those files are also kept out of git.

[Unreleased]: https://github.com/kestellohq/cms/commits/develop

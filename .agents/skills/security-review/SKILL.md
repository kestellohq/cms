---
name: security-review
description: A checklist for reviewing a Kestello change for security (authorization, CSRF, validation, escaping, SQL, uploads, zips, packages, secrets, rate limits, audit, outbound HTTP, CSP), with the core code that already handles each. Load it before committing anything that adds an endpoint, admin page, upload, outbound request, query, setting or package handling, and when reviewing someone else's change.
---

# Security review

Security comes first in Kestello. Most protections already exist in core; a review checks that new code goes through them instead of around them. Each item names where the protection lives.

## Authorization

- [ ] **Every API endpoint declares who may call it.** `Kestello\Cms\Api\Endpoint` is deny by default: an endpoint is registered only if it is `isPublic()` or declares a `capability()` **and** at least one `scopes()` entry. Public endpoints are rare and must say why.
- [ ] **Object-level checks** (one user editing another's item) go in `authorize(ApiRequest $request)`, which runs after the capability check. Use it against IDOR.
- [ ] **Destructive or account-changing actions** return true from `requiresRecentAuth()` (session users must have entered their password in the last 10 minutes; tokens are refused). Examples: `UpdateSettings`, `CreateMyToken`, `DisableTwoFactor`.
- [ ] **Admin pages** are registered with a capability (`$admin->page($path, $component, $title, $capability)`); `AdminPageController::render()` requires `admin.access` and that capability.
- [ ] **The browser never decides.** `useCan()` in `@kestello/sdk` only hides controls; the API must enforce the same check.
- [ ] New capabilities a plugin adds are namespaced (`acme-books.read`) and granted through roles, not hard-coded user checks.

## Requests

- [ ] **CSRF:** cookie-authenticated API calls must carry `X-CSRF-TOKEN` (checked in `ApiRegistry`; forms through `Kestello\Framework\Security\CsrfMiddleware`). An endpoint that can't (a form on a cached page) uses `sameOriginOnly()` and brings its own abuse checks.
- [ ] **Input is validated by schema.** `query()` and `body()` return a `Kestello\Cms\Api\Schema`; unknown body keys are rejected. Give strings `max()`, numbers `min()`/`max()`, choices `Schema::enum()`, and formats (`email`, `uri`) where they apply. Don't read `$_GET`/`$_POST` directly.
- [ ] **Rate limits:** endpoints that send mail, accept guest writes or are expensive return `[max, perSeconds]` from `rateLimit()` (backed by `Kestello\Framework\Http\RateLimiter`).

## Output

- [ ] **Vue templates escape** `{{ }}`. `v-html` is allowed only for HTML that went through `Html::sanitize()` on the server; there are few uses in core, keep it that way.
- [ ] **PHP output** uses `Kestello\Support\Html::escape()`, `Html::attributes()` and `Html::tag()`; user HTML goes through `Html::sanitize($html, $profile)` (allowlist, URL schemes checked by `Html::safeUrl()`).
- [ ] **JSON in HTML** uses the encoding flags of `PageRenderer` (`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`), so props can't close the `<script>`.
- [ ] **Scripts carry the CSP nonce.** `Kestello\Cms\Frontend\Csp` makes one per request (`$container->make(Csp::class)->nonce()`); never add inline handlers (`onclick=`) or `unsafe-inline`.

## Data

- [ ] **SQL goes through `QueryBuilder` with bindings** (`where()`, `whereIn()`, …). `QueryBuilder::raw()` and `selectRaw()` take a bindings array: values go there, never into the SQL string. Column and table names from input are checked against an allowlist.
- [ ] **Secrets never reach the browser.** Settings secrets are sent as `<key>_set` booleans (`SiteSettings::read()`); a blank write keeps the stored value. Plugin setting secrets are sealed with the app key. Never put tokens, passwords or keys in page props, logs or audit data.
- [ ] **Sensitive actions are audited** with `Kestello\Cms\Audit\AuditLog::record($event, $subject, $data)`, without secrets in `$data`.

## Files

- [ ] **Uploads go through the media library**, which allows only the enabled kinds (`MediaLibrary` reads the `media_allow_*` settings into `MediaTypes`; SVG only when `media_allow_svg` is on, sanitized with the `svg` profile). Never write an uploaded file under a name or path the user chose.
- [ ] **Zips are read with `Kestello\Support\Zip\ZipReader`**: entry count limit, `read($name, $maxBytes)`, and `extractTo($dir, $maxBytes, $maxRatio, $filter)` with size and ratio budgets. Filter out symlinks and anything outside the expected folder, as `CoreRelease::stage()` does.
- [ ] **Packages are signed.** Plugin, theme and core zips are checked by `PackageSignature::verify()` against `app.packages.trusted_keys` (`PackageInstaller::trustedKeys()`); the admin installs unsigned ones only when `app.packages.admin_unsigned` is on. Don't add paths that skip verification.

## Outbound HTTP

- [ ] **Use `Kestello\Framework\Http\Client\HttpClient`**, never raw cURL or `file_get_contents()` on URLs. It allows only http and https on ports 80 and 443, resolves the host and refuses loopback, private, link-local, CGNAT, multicast and metadata addresses, pins the connection to the vetted address, never downgrades redirects and drops credentials across hosts.
- [ ] Plugins and themes may reach only the hosts in `permissions.network` of their manifest; only core may pass `allow_private` (for admin-configured internal services).
- [ ] Webhook and import URLs typed by users are fetched through the same client.

## Review steps

1. Read the diff for new routes, endpoints, admin pages, settings, uploads, queries and outbound calls.
2. Walk the checklist above for each. Cite the line that satisfies each item, or write the fix.
3. Add tests for the denial paths: a user without the capability gets 403, a missing CSRF token gets the CSRF problem, oversized or unknown input is rejected (see [testing](../testing/SKILL.md)).
4. Report vulnerabilities found in released code privately, as `SECURITY.md` describes, never in a public issue.

## Done when

- [ ] Every item that applies is satisfied by core's mechanism, with a test for the refusal.
- [ ] No secret appears in responses, page props, logs or audit entries.
- [ ] Nothing bypasses `HttpClient`, `ZipReader`, `Html`, `Schema` or `QueryBuilder` bindings.

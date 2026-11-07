---
name: testing
description: How Kestello is tested, PHP with the in-house test runner and JavaScript with node:test. Load it before fixing a bug, adding a feature, or when a test fails and you need to run one file, one test or the full database matrix.
---

# Testing Kestello

Kestello has no PHPUnit and no Jest. PHP tests run on the in-house runner (`bin/cms test`), JavaScript tests on Node's built-in `node:test`. Both are fast enough to run on every change.

## The rule

Every fix starts with a test that fails without the fix. Write it, watch it fail for the reason in the bug report, then fix the code and watch it pass. A feature comes with tests for what it promises, including the error paths people will hit.

## PHP tests

- Live in `tests/<Area>/<Thing>Test.php`, namespace `Kestello\Tests\<Area>` (autoloaded from `tests/` by `Kestello\Framework\Testing\TestCommand`).
- Extend `Kestello\Framework\Testing\TestCase`. Every public method whose name starts with `test` runs on a fresh instance, between `setUp()` and `tearDown()`.
- Existing areas: `Admin`, `Api`, `Auth`, `Backup`, `Cms`, `Content`, `Database`, `Framework`, `Health`, `Helpers`, `I18n`, `Ide`, `Installer`, `Notifications`, `Package`, `Queue`, `Schedule`, `Search`, `Storage`, `Webhooks`. Shared helpers are in `tests/Support/` (`TestCms::make()` builds a `Cms` with no database or HTTP globals; `FakeGuard`).

```php
<?php

declare(strict_types=1);

namespace Kestello\Tests\I18n;

use Kestello\Cms\I18n\SiteLanguage;
use Kestello\Framework\Testing\TestCase;

final class SiteLanguageTest extends TestCase
{
    public function testAMalformedSettingFallsBackToConfig(): void
    {
        $this->assertSame('de', SiteLanguage::resolve('../etc', 'de'), 'a malformed setting is ignored');
    }
}
```

### Assertions

`TestCase` provides: `assertTrue`, `assertFalse`, `assertSame`, `assertNotSame`, `assertEquals`, `assertNull`, `assertNotNull`, `assertCount`, `assertGreaterThanOrEqual`, `assertArrayHasKey`, `assertArrayNotHasKey`, `assertContains` / `assertNotContains` (string or array haystack), `assertStringContainsString`, `assertStringNotContainsString`, `assertStringStartsWith`, `assertStringEndsWith`, `assertMatchesRegularExpression`, `assertFileExists`, `assertFileDoesNotExist`, `assertInstanceOf`, `assertThrows(Class::class, fn() => ..., $message)` (returns the exception) and `fail()`. Each takes a message: write it as the promise being checked ("empty translations are left out"), so a failure reads as a sentence.

`$this->tempDir()` gives an empty directory that is removed after the test. Use it for files instead of writing into the repository.

### Running PHP tests

The app container (`cms_app`) has PHP 8.5 with every extension:

```bash
docker exec cms_app php -d apc.enable_cli=1 bin/cms test                          # everything
docker exec cms_app php -d apc.enable_cli=1 bin/cms test tests/I18n               # one folder
docker exec cms_app php -d apc.enable_cli=1 bin/cms test tests/I18n/ExtractorTest.php
docker exec cms_app php -d apc.enable_cli=1 bin/cms test --filter=ExtractorTest::testJavascriptAndVue
```

`--filter` matches against `Class::method`. `npm run test:php` runs the suite through `tools/php` (local PHP 8.4 or newer, else the container).

### The database and cache matrix

By default database tests use SQLite. Extra drivers are opt in through environment variables, read by the tests that need them (for example `tests/Queue/QueueTest.php`, `tests/Framework/CacheTest.php`, `tests/Framework/SessionStoreTest.php`):

- `KESTELLO_TEST_DATABASES`: a JSON list of connection configs (`driver`, `host`, `database`, `username`, `password`).
- `KESTELLO_TEST_REDIS`: a `redis://` URL; keys use a test prefix.

`npm run test:php:matrix` (`tools/test-matrix.sh`) starts the optional `pgsql` and `redis` services and runs the suite in the app container against MySQL, PostgreSQL, Redis and SQLite, as CI does. It reads the connection settings `docker-compose.yml` builds from `.env` (`KESTELLO_MATRIX_DATABASES`, `KESTELLO_MATRIX_REDIS`). The `kestello_test` databases are created by `docker/db/*/10-test-database.sh` the first time a database volume starts; on an older volume, create `kestello_test` once, owned by the site's database user.

```bash
npm run test:php:matrix                          # everything
sh tools/test-matrix.sh tests/Database           # one folder
```

Run the matrix before committing anything that touches queries, migrations, the queue, cache or sessions. CI runs it on every pull request.

## JavaScript tests

- Live in `tests/js/<name>.test.mjs` and run with `npm test` (`node --test "tests/js/*.test.mjs"`).
- Import the module under test by relative path: `import { createCatalogue } from '../../core/resources/js/sdk/catalogue.js';`.
- Only pure modules can be tested. `@kestello/sdk`, `@kestello/app` and `@kestello/ui` resolve through the page's import map, which Node doesn't have, and Vue components need a browser. So logic lives in small pure modules next to the component or SDK (`sdk/catalogue.js`, `sdk/messageformat.js`, `ui/builder/patterns.js`, `components/content/timetable.js`) and the component only wires it up.

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { format } from '../../core/resources/js/sdk/messageformat.js';

test('plural: categories per locale', () => {
    assert.equal(format('{n, plural, one {# fajl} few {# fajla} other {# fajlova}}', { n: 3 }, 'sr'), '3 fajla');
});
```

Run one file with `node --test tests/js/messageformat.test.mjs`.

## When a test fails

1. Read the message first: it says which promise broke.
2. Run only that test with `--filter` (PHP) or the single file (JS).
3. Decide whether the code or the test is wrong. Never weaken an assertion to make it pass without saying why in the commit.
4. Tests that depend on generated files (`kestello.pot`, `.phpstorm.meta.php`, built assets) fail when those are stale: regenerate with `npm run i18n`, `npm run ide` or `npm run build`.

## Done when

- [ ] A failing test reproduced the bug before the fix (or the feature's promises are covered).
- [ ] `npm test` and the PHP suite pass; the database matrix passes when queries or storage changed.
- [ ] New logic in the admin lives in a pure, tested module.
- [ ] Temporary files, users and tokens a test created are cleaned up.

See also [code-style](../code-style/SKILL.md) for the checks that run with the tests, and [git-workflow](../git-workflow/SKILL.md) for committing.

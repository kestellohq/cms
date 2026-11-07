---
name: rest-endpoint
description: Adding an endpoint to the REST API under /api/v1 (the Endpoint class, authorization, input schemas, errors as RFC 9457 problems, registration, OpenAPI, tests). Load it when an admin screen, integration or plugin needs to read or change data over HTTP.
---

# Adding a REST endpoint

Every API endpoint is one class that declares its route, who may call it, what input it takes and what it returns. Routing, validation, authorization, rate limiting and the OpenAPI document all come from that declaration (`core/src/Cms/Api/Endpoint.php`, pipeline in `ApiRegistry.php`). The admin uses the same endpoints as integrations, so add the endpoint before the screen.

## 1. The class

Adapted from `src/Api/CreateGreeting.php` in the [hello example](https://github.com/kestellohq/examples/tree/main/plugins/hello):

```php
namespace Plugins\Hello\Api;

use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Core\Response;
use Plugins\Hello\Greetings;

/** POST /api/v1/hello/greetings {name}: greet someone. */
final class CreateGreeting extends Endpoint
{
    public function __construct(private readonly Greetings $greetings) {}   // injected from the container

    public function route(): ApiRoute { return ApiRoute::post('/hello/greetings'); }
    public function capability(): ?string { return 'hello.manage'; }
    public function scopes(): array { return ['hello:write']; }
    public function body(): ?Schema
    {
        return Schema::object(['name' => Schema::string()->min(1)->max(40)->pattern('[A-Za-z][A-Za-z0-9 _-]*')]);
    }
    public function rateLimit(): ?array { return [30, 60]; }   // 30 requests per 60 seconds, per user
    public function summary(): string { return 'Greet someone'; }
    public function tags(): array { return ['hello']; }

    public function handle(ApiRequest $request): mixed
    {
        $name = (string) $request->input('name');
        $id = $this->greetings->record($name);

        return Response::json(['data' => ['id' => $id, 'name' => $name]], 201);
    }
}
```

## 2. What each method decides

| Method | Default | Notes |
| --- | --- | --- |
| `route()` | required | `ApiRoute::get/post/put/patch/delete('/path/{param}')`, relative to `/api/v1`. Plugin paths start with `/<slug>/`. |
| `capability()` | `null` | The capability the user's role must hold. |
| `scopes()` | `[]` | Scopes a token needs (all of them). Sessions are not scope-limited. Plugin scopes are `<slug>:…`, declared with `$cms->api->scope()`. |
| `isPublic()` | `false` | Reachable without signing in (capability and scopes are then ignored). Rare: think twice. |
| `query()` | `null` | Query string schema; values are coerced from strings. |
| `body()` | `null` | JSON body schema. Unknown keys are rejected. |
| `authorize(ApiRequest)` | `true` | A per-record policy check after validation ("may this user edit this entry?"). |
| `requiresRecentAuth()` | `false` | Destructive actions: session users must have signed in within 10 minutes, tokens are refused. |
| `rateLimit()` | API default | `[max, seconds]`. Set one on anything that writes, sends mail or is expensive. |
| `resource()`, `response()`, `collection()` | | Document the response in OpenAPI. |
| `summary()`, `tags()` | | OpenAPI operation summary and grouping. |

**Deny by default:** an endpoint that is neither `isPublic()` nor declares a capability **and** at least one scope is refused at registration (`tests/Cms/ApiTest.php`, `testEndpointWithoutCapabilityOrPublicIsRefused`).

## 3. Input and output

- Read validated input with `$request->input('key')` and `$request->query('key', $default)`. Never read `$_GET`, `$_POST` or the raw body yourself.
- Schemas: `Schema::object([...])`, `string()`, `int()`, `number()`, `bool()`, `enum([...])`, `list(Schema)`, `dictionary()`, `any()`, with `->min()`, `->max()`, `->pattern()`, `->format()`, `->default()`, `->optional()`, `->nullable()`, `->description()` (`core/src/Cms/Api/Schema.php`).
- `handle()` may return a `Resource`, a `ResourceCollection`, an array (sent as `{"data": …}`), a `Response`, or `null` (204 No Content). Lists put extra numbers in `meta`.

## 4. Errors

Errors are RFC 9457 problem documents. Throw `ApiProblem` (`core/src/Cms/Api/ApiProblem.php`):

```php
throw ApiProblem::notFound(__('That greeting does not exist.'));
throw ApiProblem::validation(['name' => [__('This name is taken.')]]);
throw ApiProblem::conflict(__('Someone else changed this entry. Reload it first.'));
throw ApiProblem::forbidden();
```

Domain code (content, forms, templates) throws `ContentException` (`invalid`, `forbidden`, `notFound`, `conflict`); content endpoints turn it into a problem with `ContentEndpoint::attempt()`. Do the same in a base class when your area has its own exception. Messages are for the person using the admin: say what went wrong and what to do, and wrap them in `__()`. Stack traces never leave the server outside debug mode.

## 5. Register it

- Core: add the class to `ApiModule::ENDPOINTS` in `core/src/Cms/Api/ApiModule.php` (or register it from the area's module).
- Plugin: `$cms->api->endpoint(Api\CreateGreeting::class);` in `boot()`, after `$cms->api->scope()`.

The endpoint then appears in `GET /api/v1/openapi.json`, in **System > API** in the admin, and in the generated TypeScript client (`php bin/cms api:client`).

## 6. Tests

Add tests for what callers rely on. `tests/Cms/ApiTest.php` shows the harness: `TestCms::make()`, `FakeGuard` sessions and tokens, and a `call($method, $uri, $body, $headers)` helper that dispatches through the router. Feature areas test their endpoints through `AuthTestCase::api()` (for example `tests/Content/BlocksTest.php`).

Cover at least:

- the happy path and its status code and body;
- a guest gets 401, a user without the capability 403, a token without the scope 403;
- invalid input gets 422 with the field in `errors`;
- `authorize()` refusals, rate limits and `requiresRecentAuth()` when you use them;
- every bug fixed later, with a test that failed before the fix.

`testEveryProtectedCoreEndpointRejectsGuestsAndWrongScopes` walks every core endpoint, so a new one is checked there automatically.

```sh
npm run test:php          # or: bin/cms test tests/Cms --filter=Greeting
npm run lint
npm run i18n              # when you added messages in core
```

## Done when

- [ ] The route, capability, scopes and schemas are declared; nothing is public by accident.
- [ ] Writes have a rate limit; destructive actions consider `requiresRecentAuth()`.
- [ ] Errors are `ApiProblem`s with translated, helpful messages.
- [ ] Tests cover success, authorization and validation.
- [ ] The docs repo's REST API page mentions anything new that integrators can use.

Related: `../security-review/SKILL.md`, `../admin-page/SKILL.md`, `../testing/SKILL.md`, `../write-plugin/SKILL.md`.

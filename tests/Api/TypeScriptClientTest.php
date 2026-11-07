<?php

declare(strict_types=1);

namespace Kestello\Tests\Api;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Api\TypeScriptClient;
use Kestello\Framework\Testing\TestCase;

/** The generated TypeScript: types follow the schemas, methods follow the operations. */
final class TypeScriptClientTest extends TestCase
{
    public function testTypesFollowTheSchemas(): void
    {
        $ts = TypeScriptClient::generate($this->document());

        $this->assertStringContainsString("export type ShowThingData = {\n    id: number;\n    status: \"draft\" | \"live\";\n    note?: string | null;\n    tags: string[];\n    meta: Record<string, string | number | boolean | null>;\n    \"odd-key\": boolean;\n};", $ts);
        $this->assertStringContainsString('export type ListThingsData = Array<{', $ts, 'a list of objects');
        $this->assertStringContainsString("export interface ListThingsQuery {\n    /** Only these */\n    status?: \"draft\" | \"live\";\n    limit?: number;\n}", $ts, 'query parameters with defaults are optional');
        $this->assertStringContainsString('export type CreateThingBody = {', $ts);
    }

    public function testMethodsFollowTheOperations(): void
    {
        $ts = TypeScriptClient::generate($this->document());

        $this->assertStringContainsString("listThings: (args?: { query?: ListThingsQuery }): Promise<Answer<ListThingsData>> => request<Answer<ListThingsData>>('GET', `/things`, args?.query),", $ts);
        $this->assertStringContainsString("showThing: (args: { id: number | string }): Promise<Answer<ShowThingData>> => request<Answer<ShowThingData>>('GET', `/things/\${enc(args.id)}`, undefined),", $ts);
        $this->assertStringContainsString("createThing: (args: { body: CreateThingBody }): Promise<Answer<CreateThingData>> => request<Answer<CreateThingData>>('POST', `/things`, undefined, args.body),", $ts);
        $this->assertStringContainsString('Promise<Answer<DeleteThingData> | undefined>', $ts, 'a delete may answer 204');
        $this->assertStringContainsString('Needs the `things.manage` capability; tokens need the scope `things:write`.', $ts);
        $this->assertStringContainsString('In a browser session, needs a recent password entry.', $ts);
        $this->assertStringContainsString('acmeShowThing: (args: { id: number | string })', $ts, 'a second operation with the same name is told apart by its owner');
        $this->assertStringContainsString('From plugin:acme.', $ts);
        $this->assertStringContainsString('export function createClient(options: ClientOptions)', $ts);
        $this->assertStringContainsString('export class KestelloApiError extends Error', $ts);
    }

    public function testCommentsCannotBreakOutOfTheirBlock(): void
    {
        $doc = $this->document();
        $doc['paths']['/things']['get']['summary'] = 'Sneaky */ export const x = 1; /*';
        $ts = TypeScriptClient::generate($doc);
        $this->assertStringNotContainsString('Sneaky */', $ts);
        $this->assertStringContainsString('Sneaky * / export', $ts);
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $thing = Schema::object([
            'id' => Schema::int(),
            'status' => Schema::enum(['draft', 'live']),
            'note' => Schema::string()->nullable()->optional(),
            'tags' => Schema::list(Schema::string()),
            'meta' => Schema::dictionary(),
            'odd-key' => Schema::bool(),
        ])->toOpenApi();
        unset($thing['additionalProperties']);
        $answer = static fn(array $data) => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => $data]]]]]];
        $id = [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]];

        return [
            'openapi' => '3.0.3',
            'servers' => [['url' => 'https://example.com/api/v1']],
            'paths' => [
                '/things' => [
                    'get' => ['operationId' => 'listThings', 'summary' => 'List things', 'responses' => $answer(['type' => 'array', 'items' => $thing]), 'parameters' => [
                        ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['draft', 'live'], 'description' => 'Only these']],
                        ['name' => 'limit', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'default' => 25]],
                    ]],
                    'post' => ['operationId' => 'createThing', 'summary' => 'Add a thing', 'x-kestello-capability' => 'things.manage', 'x-kestello-recent-auth' => true,
                        'security' => [['session' => []], ['bearer' => ['things:write']]],
                        'requestBody' => ['content' => ['application/json' => ['schema' => Schema::object(['name' => Schema::string()])->toOpenApi()]]],
                        'responses' => $answer($thing)],
                ],
                '/things/{id}' => [
                    'get' => ['operationId' => 'showThing', 'parameters' => $id, 'responses' => $answer($thing)],
                    'delete' => ['operationId' => 'deleteThing', 'parameters' => $id, 'responses' => $answer(['type' => 'object'])],
                ],
                '/acme/things/{id}' => [
                    'get' => ['operationId' => 'showThing', 'x-kestello-owner' => 'plugin:acme', 'parameters' => $id, 'responses' => $answer($thing)],
                ],
            ],
        ];
    }
}

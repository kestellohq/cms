<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Api\OpenApi;
use Kestello\Cms\Api\TypeScriptClient;
use Kestello\Cms\Cms;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Console\Command;

/**
 * api:client: write the TypeScript client for this site's API.
 *
 *   php bin/cms api:client --out=frontend/src/kestello.ts
 *   php bin/cms api:client --owner=catalog      only one plugin's endpoints
 *   php bin/cms api:client --openapi            the OpenAPI document instead
 *
 * It covers every endpoint of core and the active plugins; what a caller
 * may actually do still depends on their role and token scopes.
 */
final class ApiClientCommand extends Command
{
    protected string $name = 'api:client';
    protected string $description = 'Write the TypeScript API client (--out=file, --owner=core|plugin, --openapi for the JSON document)';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $document = (new OpenApi($cms->api))->build(new Principal(0, [], 'cli', ['*']), (string) $cms->config->get('app.url', ''));

        if (isset($options['owner'])) {
            $owner = (string) $options['owner'];
            if ($owner !== 'core' && !str_contains($owner, ':')) {
                $owner = 'plugin:' . $owner;
            }
            $document['paths'] = self::only((array) $document['paths'], $owner);
            if ($document['paths'] === []) {
                $this->output->error("  No endpoints belong to {$owner}.");
                return 1;
            }
        }

        $code = isset($options['openapi'])
            ? (string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
            : TypeScriptClient::generate($document, gmdate('Y-m-d'));

        $out = $options['out'] ?? null;
        if (!is_string($out) || $out === '') {
            $this->output->line(rtrim($code));
            return 0;
        }
        $dir = dirname($out);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->output->error("  Could not create {$dir}.");
            return 1;
        }
        if (file_put_contents($out, $code) === false) {
            $this->output->error("  Could not write {$out}.");
            return 1;
        }
        $count = 0;
        foreach ((array) $document['paths'] as $methods) {
            $count += count((array) $methods);
        }
        $this->output->success("  Wrote {$out} ({$count} operations).");

        return 0;
    }

    /** @return array<string, array<string, mixed>> */
    private static function only(array $paths, string $owner): array
    {
        $out = [];
        foreach ($paths as $path => $methods) {
            foreach ((array) $methods as $method => $op) {
                if (($op['x-kestello-owner'] ?? 'core') === $owner) {
                    $out[$path][$method] = $op;
                }
            }
        }

        return $out;
    }
}

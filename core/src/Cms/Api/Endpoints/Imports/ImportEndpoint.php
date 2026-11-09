<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Imports;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Cms;
use Kestello\Cms\Import\ImportException;
use Kestello\Cms\Import\WordPress\WordPressImports;
use Kestello\Framework\Database\QueryBuilder;

/**
 * Base for /imports (System → Import). Importing writes content, files,
 * menus and redirects as the person running it: capability import.run and
 * the "admin" token scope.
 *
 * @internal
 */
abstract class ImportEndpoint extends Endpoint
{
    protected const string ID = '\d{1,18}';

    public function capability(): ?string
    {
        return 'import.run';
    }

    public function scopes(): array
    {
        return ['admin'];
    }

    public function tags(): array
    {
        return ['imports'];
    }

    protected function imports(): WordPressImports
    {
        return (Cms::instance() ?? throw new \RuntimeException('The CMS is not booted.'))->container->make(WordPressImports::class);
    }

    /** The import named in the address; 404 when there is none. */
    protected function id(ApiRequest $request): int
    {
        $id = $request->intParam('id');
        if ($this->imports()->find($id) === null) {
            throw ApiProblem::notFound('There is no such import.');
        }

        return $id;
    }

    /**
     * An import as the screen shows it; one still to start also lists the
     * people its posts can be credited to.
     *
     * @param array<string, mixed> $import
     * @return array<string, mixed>
     */
    protected static function present(array $import): array
    {
        if (($import['status'] ?? '') === 'ready') {
            $import['people'] = array_map(static fn(array $u) => ['id' => (int) $u['id'], 'username' => (string) $u['username']], QueryBuilder::table('users')
                ->select('id', 'username')->where('is_active', true)->where('role', '!=', 'user')->orderBy('username')->limit(500)->get());
        }

        return $import;
    }

    protected function attempt(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (ImportException $e) {
            throw ApiProblem::conflict($e->getMessage());
        }
    }
}

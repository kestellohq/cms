<?php

declare(strict_types=1);

namespace Kestello\Tests\Cms;

use Kestello\Cms\Api\AnswerContract;
use Kestello\Cms\Api\Endpoint;
use Kestello\Cms\Api\Endpoints\Content\DeleteEntry;
use Kestello\Cms\Api\Endpoints\Content\ListLanguages;
use Kestello\Cms\Api\Endpoints\Users\CreateUser;
use Kestello\Cms\Api\OpenApi;
use Kestello\Cms\Api\Resource;
use Kestello\Cms\Api\Schema;
use Kestello\Framework\Core\Response;
use Kestello\Framework\Testing\TestCase;

/**
 * Every endpoint says what it answers (its status, and the shape of
 * "data"), so OpenAPI and the TypeScript client tell the truth. In debug
 * mode the API checks each answer against it (ApiTest), which makes every
 * API call in the suite a contract test.
 */
final class AnswerContractTest extends TestCase
{
    /**
     * Endpoints that don't describe their answer yet (planning: stability
     * item 2.5). The list only shrinks: a new endpoint declares response()
     * or resource(), or status() 204, from the start.
     */
    private const array NOT_YET = [
        'Auth\\BeginTotp',
        'Auth\\ChangeMyPassword',
        'Auth\\ConfirmPassword',
        'Auth\\ConfirmTotp',
        'Auth\\CreateSession',
        'Auth\\DeleteMyAccount',
        'Auth\\DeleteSession',
        'Auth\\DisableTwoFactor',
        'Auth\\ExportMyData',
        'Auth\\ForgotPassword',
        'Auth\\GetMyTwoFactor',
        'Auth\\ListAuthProviders',
        'Auth\\ListMyIdentities',
        'Auth\\ListMySessions',
        'Auth\\RegenerateRecoveryCodes',
        'Auth\\ResetPassword',
        'Auth\\RevokeMySession',
        'Auth\\RevokeOtherSessions',
        'Auth\\UnlinkMyIdentity',
        'Auth\\VerifyTwoFactor',
        'Backups\\CreateBackup',
        'Backups\\DeleteBackup',
        'Backups\\ListBackups',
        'Backups\\RestoreBackup',
        'Backups\\UpdateBackupSettings',
        'Backups\\UploadBackup',
        'Blocks\\ListBlocks',
        'ContentModels\\CreateContentModel',
        'ContentModels\\DeleteContentModel',
        'ContentModels\\GetContentModel',
        'ContentModels\\ListContentModels',
        'ContentModels\\PreviewContentModel',
        'ContentModels\\UpdateContentModel',
        'Content\\CreateComment',
        'Content\\CreatePreview',
        'Content\\DeleteComment',
        'Content\\DeleteEntry',
        'Content\\DeletePreview',
        'Content\\DiffRevision',
        'Content\\ListAssignees',
        'Content\\ListComments',
        'Content\\ListContentTypes',
        'Content\\ListContentUsers',
        'Content\\ListLinkedFrom',
        'Content\\ListPreviews',
        'Content\\ListRevisions',
        'Content\\ListTaxonomies',
        'Content\\LookupEntries',
        'Content\\SaveAutosave',
        'Design\\CreateTemplate',
        'Design\\DiscardTemplateDraft',
        'Design\\GetStyles',
        'Design\\GetTemplate',
        'Design\\ListPatterns',
        'Design\\ListPreviewEntries',
        'Design\\ListTemplates',
        'Design\\PreviewBlocks',
        'Design\\PublishStyles',
        'Design\\PublishTemplateDraft',
        'Design\\ResetStyles',
        'Design\\ResetTemplate',
        'Design\\ResolveThemeUpdate',
        'Design\\RestoreStyles',
        'Design\\RestoreTemplate',
        'Design\\SaveTemplate',
        'Emails\\ResetEmail',
        'Emails\\SaveEmail',
        'Fleet\\DeleteFleetSite',
        'Fleet\\GetFleetReport',
        'Forms\\CreateForm',
        'Forms\\DeleteForm',
        'Forms\\DeleteSubmission',
        'Forms\\DownloadSubmissionFile',
        'Forms\\GetForm',
        'Forms\\GetFormToken',
        'Forms\\GetSubmission',
        'Forms\\ListForms',
        'Forms\\ListSubmissions',
        'Forms\\SubmitForm',
        'Forms\\UpdateForm',
        'Forms\\UpdateSubmission',
        'GetSite',
        'GetStatus',
        'Imports\\CancelImport',
        'Imports\\DeleteImport',
        'Imports\\GetImport',
        'Imports\\ListImports',
        'Imports\\RetryImport',
        'Imports\\StartImport',
        'Imports\\StepImport',
        'Imports\\UploadImport',
        'ListAuditLog',
        'Media\\AbortUpload',
        'Media\\DeleteMedia',
        'Media\\GetMediaLimits',
        'Media\\GetUpload',
        'Media\\PutUploadChunk',
        'Media\\StartUpload',
        'Notifications\\GetMyNotificationPreferences',
        'Notifications\\ListMyNotifications',
        'Notifications\\MarkNotificationsRead',
        'Notifications\\UpdateMyNotificationPreferences',
        'Plugins\\ActivatePlugin',
        'Plugins\\BrowseRegistry',
        'Plugins\\DeactivatePlugin',
        'Plugins\\DeletePlugin',
        'Plugins\\InstallPackage',
        'Plugins\\ListPlugins',
        'Plugins\\ReviewRegistryPackage',
        'Plugins\\ShowRegistryPackage',
        'Plugins\\SwitchBackPackage',
        'Plugins\\UpdatePackage',
        'Plugins\\UploadPackage',
        'RevokeMyToken',
        'Search\\FindInAdmin',
        'Search\\SearchSite',
        'Security\\RevokeUserSession',
        'Security\\RevokeUserToken',
        'Settings\\GetSettings',
        'Settings\\SendTestEmail',
        'Settings\\UpdateSettings',
        'System\\CancelBatch',
        'System\\ClearPageCache',
        'System\\DeleteFailedJobs',
        'System\\GetQueue',
        'System\\GetSiteHealth',
        'System\\GetStorage',
        'System\\GetSystemInfo',
        'System\\ListFailedJobs',
        'System\\ListPlatform',
        'System\\ListScheduledTasks',
        'System\\ListWaitingPages',
        'System\\ResetPlatform',
        'System\\RetryFailedJobs',
        'System\\RunScheduledTask',
        'System\\SavePageHtml',
        'System\\StartStorageMove',
        'System\\StepStorageMove',
        'System\\StopStorageMove',
        'System\\TestPlatform',
        'System\\UpdatePlatform',
        'System\\UpdateScheduledTask',
        'System\\UpdateStorageQuotas',
        'Themes\\ActivateTheme',
        'Themes\\AddFont',
        'Themes\\CreateTheme',
        'Themes\\DeleteTheme',
        'Themes\\ImportTheme',
        'Themes\\ListThemes',
        'Themes\\RemoveFont',
        'Updates\\ApplyPackageUpdate',
        'Updates\\CheckForUpdates',
        'Updates\\CheckPackageUpdates',
        'Updates\\DownloadCoreRelease',
        'Updates\\DownloadPackageUpdate',
        'Updates\\GetUpdates',
        'Updates\\RemovePackageLicense',
        'Updates\\RetryRecovery',
        'Updates\\SetPackageLicense',
        'Updates\\SwitchBackCore',
        'Updates\\UpdateCore',
        'Updates\\UploadCoreRelease',
        'Users\\CreateRole',
        'Users\\DeleteRole',
        'Users\\DeleteUser',
        'Users\\ListCapabilities',
        'Users\\ListRoles',
        'Users\\UpdateRole',
    ];

    public function testEveryNewEndpointDescribesItsAnswer(): void
    {
        $undescribed = array_values(array_filter(self::endpoints(), static fn(Endpoint $e): bool => !AnswerContract::describes($e)));
        $names = array_map(static fn(Endpoint $e): string => substr($e::class, strlen('Kestello\Cms\Api\Endpoints\\')), $undescribed);

        $this->assertSame([], array_values(array_diff($names, self::NOT_YET)), 'describe the answer: response() or resource(), or status() 204 when it answers nothing');
        $this->assertSame([], array_values(array_diff(self::NOT_YET, $names)), 'described now: take it off NOT_YET');
    }

    public function testEndpointsDeclareTheProblemsTheyAnswerWith(): void
    {
        $missing = [];
        foreach (self::endpoints() as $endpoint) {
            $source = (string) file_get_contents((string) (new \ReflectionClass($endpoint))->getFileName());
            foreach (['notFound' => 404, 'conflict' => 409] as $problem => $status) {
                if (str_contains($source, "ApiProblem::{$problem}(") && !in_array($status, $endpoint->problems(), true)) {
                    $missing[] = $endpoint::class . " throws {$status}";
                }
            }
        }
        $this->assertSame([], $missing, 'add it to problems(): return [...parent::problems(), 409];');
    }

    public function testOpenApiDocumentsTheStatusesAnEndpointAnswersWith(): void
    {
        // PHP keeps the numeric keys as integers; JSON writes them as strings
        $statuses = static fn(Endpoint $e): array => array_keys((new \ReflectionMethod(OpenApi::class, 'responses'))->invoke(null, $e));

        $this->assertSame([201, 422, 429, 401, 403], $statuses(self::bare(CreateUser::class)), 'CreateUser makes something');
        $delete = $statuses(self::bare(DeleteEntry::class));
        $this->assertTrue(in_array(404, $delete, true) && in_array(409, $delete, true), 'its address may name nothing, and it declares a conflict');
        $this->assertSame([204, 422, 429], $statuses(self::declaring(status: 204)), 'nothing to return, so no 200');
    }

    public function testAResourceAnswerMayCarryFieldsPluginsAdd(): void
    {
        $endpoint = self::declaring(resource: ContractBookResource::class);
        $this->assertSame([], AnswerContract::check($endpoint, Response::json(['data' => ['id' => 1, 'title' => 'Dune', 'acme_rating' => 5]])));
        $this->assertSame(['data.title: This field needs a value.'], AnswerContract::check($endpoint, Response::json(['data' => ['id' => 1, 'title' => null]])));
    }

    public function testAListIsCheckedItemByItem(): void
    {
        $endpoint = self::declaring(response: Schema::object(['id' => Schema::int()]), collection: true);
        $this->assertSame([], AnswerContract::check($endpoint, Response::json(['data' => [['id' => 1], ['id' => 2]]])));
        $this->assertSame(1, count(AnswerContract::check($endpoint, Response::json(['data' => [['id' => 1], ['id' => 'two']]]))));
        $this->assertSame(['"data" is not a list, but collection() says it is'], AnswerContract::check($endpoint, Response::json(['data' => ['id' => 1]])));
        $this->assertSame(['the answer has no "data"'], AnswerContract::check($endpoint, Response::json(['id' => 1])));
        $this->assertSame([], AnswerContract::check($endpoint, Response::json(['type' => 'problem'], 422)), 'any endpoint may answer 422; ApiProblem shapes it');
        $this->assertSame(['answered 409, which problems() does not list'], AnswerContract::check($endpoint, Response::json(['type' => 'problem'], 409)), 'its own problems are listed');
        $this->assertSame([], AnswerContract::check($endpoint, Response::json(['type' => 'problem'], 500)), 'server errors are not checked');
    }

    public function testAFileAnswerIsDescribedByItsMediaType(): void
    {
        $export = self::bare(\Kestello\Cms\Api\Endpoints\Redirects\ExportRedirects::class);
        $this->assertTrue(AnswerContract::describes($export));
        $csv = (new Response('source,target', 200))->withHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertSame([], AnswerContract::check($export, $csv));
        $this->assertSame(['answered application/json, but produces() says text/csv'], AnswerContract::check($export, Response::json(['data' => []])));

        $documented = (new \ReflectionMethod(OpenApi::class, 'responses'))->invoke(null, $export)[200]['content'];
        $this->assertSame(['text/csv' => ['schema' => ['type' => 'string', 'format' => 'binary']]], $documented);
    }

    public function testAMapTakesAnyKeyAndChecksEachValue(): void
    {
        $map = Schema::map(Schema::string());
        $this->assertSame([], $map->validate(['/category' => '/categoria', '/blog' => '/noticias'])[1]);
        $this->assertSame(['/blog'], array_keys($map->validate(['/blog' => 5])[1]));
        $this->assertSame(['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'maxProperties' => 1000], array_intersect_key($map->toOpenApi(), array_flip(['type', 'additionalProperties', 'maxProperties'])));
    }

    public function testTheseAnswerAnObjectThoughTheirNamesStartWithList(): void
    {
        $this->assertFalse((self::bare(ListLanguages::class))->collection());
        $this->assertSame(201, (self::bare(CreateUser::class))->status());
        $this->assertTrue(in_array(409, (self::bare(DeleteEntry::class))->problems(), true));
    }

    /** @param class-string<Endpoint> $class */
    private static function bare(string $class): Endpoint
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();     // what it declares needs none of its services
    }

    /** @return list<Endpoint> every endpoint in core */
    private static function endpoints(): array
    {
        $root = dirname(__DIR__, 2) . '/core/src/Cms/Api/Endpoints';
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $class = 'Kestello\Cms\Api\Endpoints\\' . str_replace('/', '\\', substr((string) $file, strlen($root) + 1, -4));
            if (str_ends_with((string) $file, '.php') && class_exists($class)) {
                $reflection = new \ReflectionClass($class);
                if (!$reflection->isAbstract() && $reflection->isSubclassOf(Endpoint::class)) {
                    $found[] = $reflection->newInstanceWithoutConstructor();
                }
            }
        }
        usort($found, static fn(Endpoint $a, Endpoint $b): int => strcmp($a::class, $b::class));

        return $found;
    }

    /** @param class-string<Resource>|null $resource */
    private static function declaring(?Schema $response = null, ?string $resource = null, bool $collection = false, int $status = 200): Endpoint
    {
        return new class ($response, $resource, $collection, $status) extends Endpoint {
            public function __construct(private ?Schema $shape, private ?string $class, private bool $list, private int $answer) {}
            public function status(): int
            {
                return $this->answer;
            }
            public function route(): \Kestello\Cms\Api\ApiRoute
            {
                return \Kestello\Cms\Api\ApiRoute::get('/books');
            }
            public function isPublic(): bool
            {
                return true;
            }
            public function response(): ?Schema
            {
                return $this->shape;
            }
            public function resource(): ?string
            {
                return $this->class;
            }
            public function collection(): bool
            {
                return $this->list;
            }
            public function handle(\Kestello\Cms\Api\ApiRequest $request): mixed
            {
                return null;
            }
        };
    }
}

final class ContractBookResource extends Resource
{
    public const string NAME = 'contract-book';

    public static function properties(): array
    {
        return ['id' => Schema::int(), 'title' => Schema::string()];
    }

    protected function fields(\Kestello\Contracts\Auth\Principal $principal): array
    {
        return $this->row;
    }
}

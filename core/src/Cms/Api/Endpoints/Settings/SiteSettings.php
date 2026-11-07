<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Settings;

use DateTimeZone;
use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Cms\I18n\Labels;
use Kestello\Cms\I18n\SiteLanguage;
use Kestello\Contracts\Auth\Principal;
use Kestello\Framework\Settings\Settings;
use Kestello\Support\AppKey;

/**
 * The site settings the admin edits, as schema-described groups (each one
 * a tab in the admin, rendered by the schema form). Every field is
 * optional on write: send only what changes.
 *
 * Secrets never leave the server: reads carry "<key>_set" instead, a blank
 * secret keeps the stored value and null clears it.
 *
 * Plugins add groups with $cms->admin->settings(): they are stored under
 * "plugin.<slug>.<tab>.<field>", their secrets sealed with the app key (the
 * same format as `plugin:config --secret`), and each tab can ask for its
 * own capability on top of settings.manage.
 *
 * @internal
 */
final class SiteSettings
{
    /**
     * Every group: core first, then plugin tabs.
     *
     * @return array<string, array{label: string, description: string, schema: Schema, labels: array<string, string>, secrets: list<string>, defaults: array<string, mixed>, prefix: string, sealed: bool, capability: string, component: ?string, owner: string}>
     */
    public static function groups(): array
    {
        $groups = self::coreGroups();
        foreach (Cms::instance()?->admin->settingsTabs() ?? [] as $tab) {
            $defaults = [];
            foreach ($tab['schema']->toOpenApi()['properties'] ?? [] as $field => $def) {
                $defaults[$field] = $tab['defaults'][$field] ?? $def['default'] ?? match ($def['type'] ?? 'string') {
                    'boolean' => false,
                    'integer' => 0,
                    'number' => 0.0,
                    default => '',
                };
            }
            $groups[$tab['id']] = [
                'label' => $tab['label'],
                'description' => $tab['description'],
                'schema' => $tab['schema'],
                'labels' => $tab['labels'],
                'secrets' => $tab['secrets'],
                'defaults' => $defaults,
                'prefix' => $tab['prefix'],
                'sealed' => true,
                'capability' => $tab['capability'],
                'component' => $tab['component'],
                'owner' => $tab['owner'],
            ];
        }

        return $groups;
    }

    /** The groups this principal may read and change. @return array<string, array<string, mixed>> */
    public static function groupsFor(Principal $principal): array
    {
        return array_filter(self::groups(), static fn(array $g) => $principal->can($g['capability']));
    }

    /** @return array<string, array<string, mixed>> */
    private static function coreGroups(): array
    {
        $config = Cms::instance()?->config;
        $core = ['prefix' => '', 'sealed' => false, 'capability' => 'settings.manage', 'component' => null, 'owner' => 'core'];

        return [
            'general' => [
                'label' => 'General',
                'description' => 'The name, address and clock of your site.',
                'schema' => Schema::object([
                    'site_name' => Schema::string()->min(1)->max(100)->optional()->description('Shown in the browser tab, emails and the admin.'),
                    'site_description' => Schema::string()->max(300)->optional()->description('One sentence about the site, used by search engines and themes.'),
                    'site_url' => Schema::string()->max(255)->format('uri')->optional()->description('The public address, starting with https://.'),
                    'timezone' => Schema::enum(DateTimeZone::listIdentifiers())->optional()->description('Dates and scheduled tasks use this clock.'),
                    SiteLanguage::SETTING => Schema::enum(SiteLanguage::choices())->optional()
                        ->description('The admin, emails and the text your theme shows on its own use this language. Language packs add more.'),
                ]),
                'labels' => ['site_name' => 'Site name', 'site_description' => 'Tagline', 'site_url' => 'Site address', 'timezone' => 'Timezone', SiteLanguage::SETTING => 'Language'],
                'secrets' => [],
                'defaults' => [
                    'site_name' => (string) $config?->get('app.name', ''),
                    'site_description' => (string) $config?->get('app.description', ''),
                    'site_url' => (string) $config?->get('app.url', ''),
                    'timezone' => (string) $config?->get('app.timezone', 'UTC'),
                    SiteLanguage::SETTING => SiteLanguage::resolve(null, (string) $config?->get('app.locale', 'en')),
                ],
            ] + $core,
            'reading' => [
                'label' => 'Reading',
                'description' => 'What visitors and search engines see.',
                'schema' => Schema::object([
                    ...self::permalinkFields(),
                    'front_page' => Schema::enum(self::frontPageChoices())->optional()
                        ->description('The page shown at the site address. Its own address then redirects there.'),
                    'feed_items' => Schema::int()->min(1)->max(50)->optional()->description('How many of the newest entries feed readers get.'),
                    'discourage_search' => Schema::bool()->optional()
                        ->description('For a site that isn\'t ready: every page asks search engines not to list it, and robots.txt turns them away. Visitors can still open it.'),
                    'robots_txt' => Schema::string()->max(5000)->format('multiline')->optional()
                        ->description('Extra lines for robots.txt, after the built-in ones (admin and API are always excluded, and the sitemap is listed).'),
                ]),
                'labels' => self::permalinkLabels() + ['front_page' => 'Home page', 'feed_items' => 'Entries in feeds', 'discourage_search' => 'Hide the site from search engines', 'robots_txt' => 'robots.txt additions'],
                'secrets' => [],
                'defaults' => array_combine(array_map(static fn($t) => 'permalink_' . $t->name, self::datedTypes()), array_map(static fn($t) => $t->getPermalink(), self::datedTypes())) + ['front_page' => '', 'feed_items' => 10, 'discourage_search' => false, 'robots_txt' => ''],
            ] + $core,
            'mail' => [
                'label' => 'Email',
                'description' => 'How the site sends password resets and notifications. Without SMTP, PHP mail() is used and messages often land in spam.',
                'schema' => Schema::object([
                    'mail_from_address' => Schema::string()->max(255)->format('email')->optional()->description('Replies go here, so use a mailbox someone reads.'),
                    'mail_from_name' => Schema::string()->max(100)->optional(),
                    'smtp_host' => Schema::string()->max(255)->pattern('[A-Za-z0-9.-]*')->optional()->description('Leave empty to use PHP mail().'),
                    'smtp_port' => Schema::int()->min(1)->max(65535)->optional(),
                    'smtp_encryption' => Schema::enum(['tls', 'ssl', 'none'])->optional()->description('tls for port 587, ssl for port 465.'),
                    'smtp_username' => Schema::string()->max(255)->optional(),
                    'smtp_password' => Schema::string()->max(1000)->nullable()->optional(),
                ]),
                'labels' => ['mail_from_address' => 'From address', 'mail_from_name' => 'From name', 'smtp_host' => 'SMTP server', 'smtp_port' => 'Port', 'smtp_encryption' => 'Encryption', 'smtp_username' => 'Username', 'smtp_password' => 'Password'],
                'secrets' => ['smtp_password'],
                'defaults' => ['mail_from_address' => '', 'mail_from_name' => '', 'smtp_host' => '', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => '', 'smtp_password' => ''],
                // Password reset links travel through this server: whoever sets it can read them.
                'capability' => 'system.manage',
            ] + $core,
            'media' => [
                'label' => 'Media',
                'description' => 'What people may upload to the media library, and how images are prepared for pages. Images are always allowed; scripts and web pages never are.',
                'schema' => Schema::object([
                    'media_max_upload_mb' => Schema::int()->min(1)->max(5120)->optional()->description('The largest file anyone can upload, in megabytes.'),
                    'media_max_megapixels' => Schema::int()->min(1)->max(500)->optional()->description('Larger images are refused: decoding them could exhaust the server\'s memory.'),
                    'media_allow_document' => Schema::bool()->optional()->description('PDF, Word, Excel, PowerPoint, OpenDocument, RTF, text and CSV.'),
                    'media_allow_audio' => Schema::bool()->optional()->description('MP3, M4A, Ogg, WAV and FLAC.'),
                    'media_allow_video' => Schema::bool()->optional()->description('MP4, M4V, MOV and WebM.'),
                    'media_allow_archive' => Schema::bool()->optional()->description('ZIP files. Off by default: archives can hide anything.'),
                    'media_allow_svg' => Schema::bool()->optional()->description('Vector images. Scripts, links and anything that isn\'t drawing are removed before they are stored.'),
                    'media_max_edge' => Schema::int()->min(0)->max(10000)->optional()->description('Larger photos are scaled down on upload. 0 keeps every original at full size.'),
                    'media_avif' => Schema::bool()->optional()->description('The smallest files for modern browsers. Making them takes more server time.'),
                    'media_quality_avif' => Schema::int()->min(1)->max(100)->optional()->description('1–100. 50 looks like JPEG at 80 in about half the bytes.'),
                    'media_quality_webp' => Schema::int()->min(1)->max(100)->optional(),
                    'media_quality_jpeg' => Schema::int()->min(1)->max(100)->optional()->description('For browsers without WebP. Higher looks better and weighs more.'),
                    'media_optimize' => Schema::bool()->optional()->description('Squeezes JPEG and PNG sizes further without changing a pixel, when the server has jpegoptim or oxipng.'),
                    'media_pdf_thumbnails' => Schema::bool()->optional()->description('Shows the first page of each PDF as its picture, when the server has Ghostscript.'),
                ]),
                'labels' => ['media_max_upload_mb' => 'Largest upload (MB)', 'media_max_megapixels' => 'Largest image (megapixels)', 'media_allow_document' => 'Documents', 'media_allow_audio' => 'Audio', 'media_allow_video' => 'Video', 'media_allow_archive' => 'Archives', 'media_allow_svg' => 'SVG images', 'media_max_edge' => 'Largest photo edge (px)', 'media_avif' => 'Make AVIF', 'media_quality_avif' => 'AVIF quality', 'media_quality_webp' => 'WebP quality', 'media_quality_jpeg' => 'JPEG quality', 'media_optimize' => 'Optimize images losslessly', 'media_pdf_thumbnails' => 'PDF thumbnails'],
                'secrets' => [],
                'defaults' => ['media_max_upload_mb' => 64, 'media_max_megapixels' => 50, 'media_allow_document' => true, 'media_allow_audio' => true, 'media_allow_video' => true, 'media_allow_archive' => false, 'media_allow_svg' => false, 'media_max_edge' => 2560, 'media_avif' => true, 'media_quality_avif' => 50, 'media_quality_webp' => 75, 'media_quality_jpeg' => 80, 'media_optimize' => true, 'media_pdf_thumbnails' => true],
            ] + $core,
            'performance' => [
                'label' => 'Performance',
                'description' => 'Pages for visitors who aren\'t signed in are kept ready, and refreshed when the content on them changes.',
                'schema' => Schema::object([
                    'page_cache' => Schema::bool()->optional()->description('Off while debug mode is on, unless you turn it on here.'),
                    'page_cache_ttl' => Schema::int()->min(60)->max(604800)->optional()->description('Seconds a page is kept when nothing changes. Edits refresh pages right away anyway.'),
                    'cdn_max_age' => Schema::int()->min(0)->max(86400)->optional()->description('Seconds a CDN or proxy in front of the site may keep pages (0: it asks every time). It can\'t be told when pages change, so keep this short.'),
                ]),
                'labels' => ['page_cache' => 'Cache pages', 'page_cache_ttl' => 'Keep pages for', 'cdn_max_age' => 'CDN cache time'],
                'secrets' => [],
                'defaults' => ['page_cache' => !(bool) $config?->get('app.debug', false), 'page_cache_ttl' => 86400, 'cdn_max_age' => 0],
            ] + $core,
            'snippets' => [
                'label' => 'Code',
                'description' => 'HTML added to every public page, such as analytics or a site verification tag. Scripts get the security nonce automatically; onclick-style attributes don\'t run. Never shown on the admin or sign-in pages.',
                'schema' => Schema::object([
                    'snippet_head' => Schema::string()->max(20000)->format('multiline')->optional()->description('Inside <head>, after the built-in tags.'),
                    'snippet_body' => Schema::string()->max(20000)->format('multiline')->optional()->description('Right after <body> opens (for example a tag manager\'s <noscript> part).'),
                    'snippet_footer' => Schema::string()->max(20000)->format('multiline')->optional()->description('At the end of <body>.'),
                ]),
                'labels' => ['snippet_head' => 'In the head', 'snippet_body' => 'At the start of the body', 'snippet_footer' => 'At the end of the body'],
                'secrets' => [],
                'defaults' => ['snippet_head' => '', 'snippet_body' => '', 'snippet_footer' => ''],
            ] + ['capability' => 'snippets.manage'] + $core,
        ];
    }

    /** One "Post addresses" choice per dated type. @return array<string, Schema> */
    private static function permalinkFields(): array
    {
        $fields = [];
        foreach (self::datedTypes() as $type) {
            $fields['permalink_' . $type->name] = Schema::enum(\Kestello\Cms\Content\Permalinks::choices($type))->optional()
                ->description('Old addresses keep working: they redirect to the new pattern.');
        }

        return $fields;
    }

    /** @return array<string, string> */
    private static function permalinkLabels(): array
    {
        $labels = [];
        foreach (self::datedTypes() as $type) {
            $labels['permalink_' . $type->name] = $type->singular() . ' addresses';
        }

        return $labels;
    }

    /** @return list<\Kestello\Cms\Content\ContentType> */
    private static function datedTypes(): array
    {
        return array_values(array_filter(Cms::instance()?->content->types() ?? [], \Kestello\Cms\Content\Permalinks::supports(...)));
    }

    /** "" (the theme's home page) and every published page, by title. @return array<string, string> */
    private static function frontPageChoices(): array
    {
        $choices = ['' => "The theme's home page"];
        try {
            $types = array_values(array_map(static fn($t) => $t->name, array_filter(Cms::instance()?->content->types() ?? [], static fn($t) => $t->getPath() === '')));
            if ($types !== []) {
                foreach (\Kestello\Cms\Content\Entry::query()->whereIn('type', $types)->published()->orderBy('title')->limit(500)->get()->all() as $page) {
                    $choices[(string) $page->getKey()] = (string) $page->title;
                }
            }
        } catch (\Throwable) {
            // before install or without content tables: only the theme's page
        }

        return $choices;
    }

    /** Current values per group; secrets become "<key>_set" booleans. @return array<string, array<string, mixed>> */
    public static function read(?Principal $principal = null): array
    {
        $s = Settings::getInstance();
        $out = [];
        foreach ($principal === null ? self::groups() : self::groupsFor($principal) as $id => $group) {
            $out[$id] = [];
            foreach ($group['defaults'] as $key => $default) {
                $value = $s->get($group['prefix'] . $key, $default);
                if (in_array($key, $group['secrets'], true)) {
                    $out[$id][$key . '_set'] = (string) $value !== '';
                    continue;
                }
                $out[$id][$key] = match (true) {
                    is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                    is_int($default) => (int) $value,
                    is_float($default) => (float) $value,
                    default => (string) $value,
                };
            }
        }

        return $out;
    }

    /**
     * Store validated values of one group (secrets already resolved: a key
     * present with '' clears it). Returns the keys written.
     *
     * @param array<string, mixed> $group
     * @param array<string, mixed> $values
     * @return list<string>
     */
    public static function write(string $id, array $group, array $values): array
    {
        $rows = [];
        foreach ($values as $key => $value) {
            $name = $group['prefix'] . $key;
            $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            if ($group['sealed'] && in_array($key, $group['secrets'], true) && $value !== '') {
                $value = AppKey::secretStore()->seal($value, 'settings.' . $name);
            }
            $rows[$name] = $value;
        }
        if ($rows !== []) {
            // Plugin keys share the group plugin:config lists ("plugin.<slug>").
            $storeGroup = $group['prefix'] === '' ? null : implode('.', array_slice(explode('.', $group['prefix']), 0, 2));
            Settings::getInstance()->setMany($rows, $storeGroup ?? $id);
        }

        return array_keys($values);
    }

    /** Group descriptions for the admin (schema as OpenAPI). @return list<array<string, mixed>> */
    public static function describe(?Principal $principal = null): array
    {
        $names = [];
        try {
            foreach (Cms::instance()?->extensions()->plugins() ?? [] as $slug => $package) {
                $names['plugin:' . $slug] = $package->manifest->name;
            }
        } catch (\LogicException) {
            // extensions not booted (tests): fall back to slugs
        }

        $out = [];
        foreach ($principal === null ? self::groups() : self::groupsFor($principal) as $id => $group) {
            $domain = Labels::domain($group['owner']);
            $schema = Labels::schema($group['schema']->toOpenApi(), $domain);
            unset($schema['required']); // every save is partial: no field is required
            $out[] = [
                'id' => $id,
                'label' => Labels::text($group['label'], $domain),
                'description' => Labels::text($group['description'], $domain),
                'schema' => $schema,
                'labels' => (object) Labels::choices($group['labels'], $domain),
                'secrets' => $group['secrets'],
                'component' => $group['component'],
                'source' => $group['owner'] === 'core' ? null : ($names[$group['owner']] ?? explode(':', $group['owner'], 2)[1]),
            ];
        }

        return $out;
    }

    /** Keep config/app.php in step with the general settings (read before the database is). */
    public static function syncAppConfig(array $general): void
    {
        $path = Cms::instance()?->paths->config('app.php');
        if ($path === null || !is_file($path) || !is_writable($path)) {
            return;
        }
        // A hand-written file (getenv(), constants, other code) would lose that code when written back
        // as plain values: leave it alone. The settings saved in the database still apply.
        $source = (string) file_get_contents($path);
        if (preg_match('/getenv\s*\(|\$_(ENV|SERVER)|\bdefined?\s*\(|\benv\s*\(|\bfunction\b|\bfn\s*\(|\brequire\b|\binclude\b/i', $source) === 1) {
            return;
        }
        $config = require $path;
        if (!is_array($config)) {
            return;
        }
        $config['name'] = $general['site_name'];
        $config['description'] = $general['site_description'];
        $config['url'] = $general['site_url'];
        $config['timezone'] = $general['timezone'];

        $tmp = $path . '.tmp.' . getmypid();
        file_put_contents($tmp, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n", LOCK_EX);
        rename($tmp, $path);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
    }
}

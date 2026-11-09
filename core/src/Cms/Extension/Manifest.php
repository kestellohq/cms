<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

use Kestello\Framework\Support\Semver;

/**
 * A validated plugin.json or theme.json.
 *
 * Validation is strict on purpose: a package with a broken manifest is
 * never loaded, so it cannot half-work.
 *
 * @api
 */
final readonly class Manifest
{
    private const string SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const string GITHUB = '#^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})/[A-Za-z0-9._-]{1,100}$#';
    private const string NAMESPACE = '/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*\\\\$/';

    /**
     * @param array<string, string> $dependencies slug => constraint
     * @param list<string>          $conflicts
     * @param list<string>          $extensions   required PHP extensions
     * @param list<string>          $replaces     contracts this package rebinds
     * @param array<string, mixed>  $permissions  declared permissions (disclosure)
     * @param array<string, mixed>  $raw          the full decoded manifest
     * @param list<string>          $stylesheets  global stylesheets for pages rendered in this theme
     * @param array{github: string}|array{url: string, license: bool}|null $updates where new versions come from (UpdateSource)
     * @param list<string>          $signingKeys  key ids that may sign this package's next versions (key rotation)
     */
    public function __construct(
        public PackageType $type,
        public string $slug,
        public string $name,
        public string $version,
        public string $description,
        public string $requiresCore,
        public ?string $requiresPhp,
        public array $extensions,
        public array $dependencies,
        public array $conflicts,
        public ?string $namespace,
        public ?string $provider,
        public ?string $parent,
        public array $replaces,
        public array $permissions,
        public array $raw,
        public array $stylesheets = [],
        public ?array $updates = null,
        public array $signingKeys = [],
    ) {}

    /**
     * Load and validate the manifest in $directory. The directory name must equal the slug.
     *
     * @throws ManifestException
     */
    public static function load(string $directory, PackageType $type): self
    {
        $file = rtrim($directory, '/') . '/' . $type->manifestFile();

        if (!is_file($file)) {
            throw new ManifestException("Missing {$type->manifestFile()} in {$directory}.");
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ManifestException("{$file} is not valid JSON: {$e->getMessage()}");
        }

        if (!is_array($data)) {
            throw new ManifestException("{$file} must contain a JSON object.");
        }

        $manifest = self::fromArray($data, $type, $file);

        if (basename(rtrim($directory, '/')) !== $manifest->slug) {
            throw ManifestException::invalid($file, [sprintf(
                'directory name "%s" must match slug "%s"',
                basename(rtrim($directory, '/')),
                $manifest->slug,
            )]);
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $d
     * @throws ManifestException
     */
    public static function fromArray(array $d, PackageType $type, string $file = 'manifest'): self
    {
        $errors = [];
        $str = static fn(string $k): ?string => isset($d[$k]) && is_string($d[$k]) && $d[$k] !== '' ? $d[$k] : null;

        $slug = $str('slug');
        if ($slug === null || preg_match(self::SLUG, $slug) !== 1 || strlen($slug) > 64) {
            $errors[] = 'slug is required: lowercase letters, digits and single dashes, max 64 chars';
        }

        $name = $str('name');
        if ($name === null) {
            $errors[] = 'name is required';
        }

        $version = $str('version');
        if ($version === null || !Semver::isValid($version)) {
            $errors[] = 'version is required and must be semver (e.g. 1.0.0)';
        }

        $requires = is_array($d['requires'] ?? null) ? $d['requires'] : [];
        $core = is_string($requires['core'] ?? null) ? $requires['core'] : null;
        if ($core === null || !Semver::isValidConstraint($core)) {
            $errors[] = 'requires.core is required and must be a version constraint (e.g. "^1.0")';
        }

        $php = is_string($requires['php'] ?? null) ? $requires['php'] : null;
        if ($php !== null && !Semver::isValidConstraint($php)) {
            $errors[] = 'requires.php must be a version constraint';
        }

        $extensions = $requires['extensions'] ?? [];
        if (!is_array($extensions) || array_filter($extensions, 'is_string') !== $extensions) {
            $errors[] = 'requires.extensions must be a list of extension names';
            $extensions = [];
        }

        $dependencies = $d['dependencies'] ?? [];
        if (!is_array($dependencies)) {
            $errors[] = 'dependencies must be an object of slug: constraint';
            $dependencies = [];
        }
        foreach ($dependencies as $depSlug => $constraint) {
            if (!is_string($depSlug) || preg_match(self::SLUG, $depSlug) !== 1 || !is_string($constraint) || !Semver::isValidConstraint($constraint)) {
                $errors[] = sprintf('dependencies.%s must be a valid slug with a version constraint', (string) $depSlug);
            }
        }
        if ($slug !== null && isset($dependencies[$slug])) {
            $errors[] = 'a package cannot depend on itself';
        }

        $conflicts = $d['conflicts'] ?? [];
        if (!is_array($conflicts) || array_filter($conflicts, 'is_string') !== $conflicts) {
            $errors[] = 'conflicts must be a list of slugs';
            $conflicts = [];
        }

        $namespace = $str('namespace');
        if ($namespace !== null) {
            $namespace = rtrim($namespace, '\\') . '\\';
            if (preg_match(self::NAMESPACE, $namespace) !== 1) {
                $errors[] = 'namespace must be a valid PHP namespace (e.g. "Acme\\\\Seo\\\\")';
            } elseif (str_starts_with($namespace, 'Kestello\\')) {
                $errors[] = 'namespace must not be inside Kestello\\ (reserved for core)';
            }
        }

        $provider = $str('provider');
        if ($provider !== null && ($namespace === null || !str_starts_with($provider, $namespace))) {
            $errors[] = 'provider must be a class inside the declared namespace';
        }

        $parent = $type === PackageType::Theme ? $str('parent') : null;
        if ($parent !== null && (preg_match(self::SLUG, $parent) !== 1 || $parent === $slug)) {
            $errors[] = 'parent must be the slug of another theme';
        }

        $replaces = $d['replaces'] ?? [];
        if (!is_array($replaces) || array_filter($replaces, 'is_string') !== $replaces) {
            $errors[] = 'replaces must be a list of contract names';
            $replaces = [];
        }

        $permissions = $d['permissions'] ?? [];
        if (!is_array($permissions)) {
            $errors[] = 'permissions must be an object';
            $permissions = [];
        }

        $stylesheets = $d['stylesheets'] ?? [];
        if (!is_array($stylesheets) || !array_is_list($stylesheets)) {
            $errors[] = 'stylesheets must be a list';
            $stylesheets = [];
        }
        foreach ($stylesheets as $sheet) {
            // "/assets/css/x.css" (site path) or "css/x.css" (inside this package's assets/)
            if (!is_string($sheet) || preg_match('#^(/(?!/)[A-Za-z0-9_./-]+|(?!.*\.\.)[A-Za-z0-9_][A-Za-z0-9_./-]*)\.css$#', $sheet) !== 1) {
                $errors[] = 'stylesheets entries must be .css paths ("/assets/…" or relative to assets/)';
                $stylesheets = [];
                break;
            }
        }

        $updates = null;
        if (array_key_exists('updates', $d)) {
            [$updates, $problem] = self::updates($d['updates']);
            if ($problem !== null) {
                $errors[] = $problem;
            }
        }

        $signingKeys = $d['signing_keys'] ?? [];
        if (!is_array($signingKeys) || !array_is_list($signingKeys) || count($signingKeys) > 10 || array_filter($signingKeys, static fn(mixed $k): bool => is_string($k) && preg_match('/^[a-f0-9]{16}$/', $k) === 1) !== $signingKeys) {
            $errors[] = 'signing_keys must be a list of key ids (16 hex characters, as package:keygen prints them)';
            $signingKeys = [];
        }

        if ($errors !== []) {
            throw ManifestException::invalid($file, $errors);
        }

        /** @var string $slug */
        /** @var string $name */
        /** @var string $version */
        /** @var string $core */
        return new self(
            type: $type,
            slug: $slug,
            name: $name,
            version: ltrim($version, 'v'),
            description: $str('description') ?? '',
            requiresCore: $core,
            requiresPhp: $php,
            extensions: array_values($extensions),
            dependencies: $dependencies,
            conflicts: array_values($conflicts),
            namespace: $namespace,
            provider: $provider,
            parent: $parent,
            replaces: array_values($replaces),
            permissions: $permissions,
            raw: $d,
            stylesheets: array_values($stylesheets),
            updates: $updates,
            signingKeys: array_values(array_unique($signingKeys)),
        );
    }

    /**
     * "updates": {"github": "owner/name"} or {"url": "https://…", "license": true}.
     *
     * @return array{0: array{github: string}|array{url: string, license: bool}|null, 1: ?string}
     */
    private static function updates(mixed $u): array
    {
        if (!is_array($u)) {
            return [null, 'updates must be an object: {"github": "owner/name"} or {"url": "https://…"}'];
        }
        $unknown = array_diff(array_keys($u), ['github', 'url', 'license']);
        if ($unknown !== []) {
            return [null, 'updates has unknown keys: ' . implode(', ', $unknown)];
        }
        if (isset($u['github']) === isset($u['url'])) {
            return [null, 'updates needs exactly one of "github" or "url"'];
        }
        if (isset($u['github'])) {
            if (!is_string($u['github']) || preg_match(self::GITHUB, $u['github']) !== 1 || array_key_exists('license', $u)) {
                return [null, 'updates.github must be a repository like "acme/events" (GitHub releases take no license key)'];
            }

            return [['github' => $u['github']], null];
        }
        $url = $u['url'];
        if (!is_string($url) || strlen($url) > 500 || !self::isFeedUrl($url)) {
            return [null, 'updates.url must be an https address with no user name, password or fragment'];
        }
        if (array_key_exists('license', $u) && !is_bool($u['license'])) {
            return [null, 'updates.license must be true or false'];
        }

        return [['url' => $url, 'license' => $u['license'] ?? false], null];
    }

    private static function isFeedUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') !== ''
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && (!isset($parts['port']) || $parts['port'] === 443);
    }
}

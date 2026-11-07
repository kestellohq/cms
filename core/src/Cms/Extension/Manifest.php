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
    private const string NAMESPACE = '/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*\\\\$/';

    /**
     * @param array<string, string> $dependencies slug => constraint
     * @param list<string>          $conflicts
     * @param list<string>          $extensions   required PHP extensions
     * @param list<string>          $replaces     contracts this package rebinds
     * @param array<string, mixed>  $permissions  declared permissions (disclosure)
     * @param array<string, mixed>  $raw          the full decoded manifest
     * @param list<string>          $stylesheets  global stylesheets for pages rendered in this theme
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
        );
    }
}

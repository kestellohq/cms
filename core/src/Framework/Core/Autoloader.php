<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

/**
 * PSR-4 compatible autoloader.
 *
 * Maps namespace prefixes to base directories and loads the corresponding
 * class files automatically.
 */
final class Autoloader
{
    /**
     * Registered namespace prefix => list of base directories.
     *
     * @var array<string, list<string>>
     */
    private array $prefixes = [];

    /**
     * Register this autoloader with the SPL autoload stack.
     */
    public function register(): void
    {
        spl_autoload_register([$this, 'loadClass']);
    }

    /**
     * Unregister this autoloader from the SPL autoload stack.
     */
    public function unregister(): void
    {
        spl_autoload_unregister([$this, 'loadClass']);
    }

    /**
     * Add a base directory for a namespace prefix.
     *
     * @param string $prefix  The namespace prefix (e.g. "App\\").
     * @param string $baseDir The base directory for class files (e.g. "/project/src").
     */
    public function addNamespace(string $prefix, string $baseDir): void
    {
        // Normalise the prefix to end with a single backslash.
        $prefix = trim($prefix, '\\') . '\\';

        // Normalise the base directory to end with a single directory separator.
        $baseDir = rtrim($baseDir, '/') . '/';

        $this->prefixes[$prefix] ??= [];
        $this->prefixes[$prefix][] = $baseDir;
    }

    /**
     * Attempt to load the class file for the given fully-qualified class name.
     *
     * @param string $class Fully-qualified class name.
     */
    public function loadClass(string $class): bool
    {
        foreach ($this->prefixes as $prefix => $dirs) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));
            $relativeFile = str_replace('\\', '/', $relativeClass) . '.php';

            foreach ($dirs as $baseDir) {
                $file = $baseDir . $relativeFile;

                if (is_file($file)) {
                    require $file;
                    return true;
                }
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

/*
 * Kestello's PHP code style: PER Coding Style 2.0 (the PHP-FIG standard that grew out of
 * PSR-12), plus a few rules that keep diffs small. Run it with:
 *
 *   npm run format        fix every file
 *   npm run lint:php      check only (what CI runs)
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->ignoreVCSIgnored(true) // the local site's own plugins and themes in content/ are not ours
    ->exclude(['node_modules', 'storage', 'tools', 'vendor', 'core/releases', 'dist'])
    ->notPath(['core/resources/assets', 'config/installed.php'])
    ->name(['*.php'])
    ->append([__DIR__ . '/bin/cms']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules([
        '@PER-CS2x0' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'single_quote' => true,
        'no_trailing_whitespace_in_string' => false,
    ]);

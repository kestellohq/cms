<?php

declare(strict_types=1);

namespace Kestello\Cms\Extension;

/**
 * Static checks for a plugin or theme (spec/modularity.md §8.3).
 *
 * Not a sandbox — a review aid. Run before installing third-party code;
 * the registry runs it on every submission.
 */
final class PackageChecker
{
    private const array DANGEROUS_FUNCTIONS = [
        'eval' => 'executes arbitrary code',
        'assert' => 'can execute code strings on old configurations',
        'create_function' => 'executes arbitrary code',
        'exec' => 'runs shell commands',
        'shell_exec' => 'runs shell commands',
        'system' => 'runs shell commands',
        'passthru' => 'runs shell commands',
        'proc_open' => 'runs shell commands',
        'popen' => 'runs shell commands',
        'pcntl_exec' => 'runs processes',
        'unserialize' => 'object injection risk; use json_decode',
        'extract' => 'can overwrite variables from untrusted input',
        'curl_init' => 'bypasses the core HTTP client (network allowlist, SSRF guard)',
        'curl_exec' => 'bypasses the core HTTP client (network allowlist, SSRF guard)',
        'fsockopen' => 'raw network access; use the core HTTP client',
        'stream_socket_client' => 'raw network access; use the core HTTP client',
        'mail' => 'bypasses the core mailer',
        'phpinfo' => 'leaks server configuration',
    ];

    private const array SUPERGLOBALS = ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES'];

    /**
     * @return list<array{level: string, file: string, line: int, message: string}>
     */
    public function check(string $directory, PackageType $type): array
    {
        $findings = [];

        try {
            $manifest = Manifest::load($directory, $type);
        } catch (ManifestException $e) {
            return [['level' => 'error', 'file' => $type->manifestFile(), 'line' => 0, 'message' => $e->getMessage()]];
        }

        if ($manifest->requiresCore === '*') {
            $findings[] = ['level' => 'warning', 'file' => $type->manifestFile(), 'line' => 0, 'message' => 'requires.core is "*": declare the core versions you actually support'];
        }

        $sources = rtrim($directory, '/') . '/resources/js';
        if (is_dir($sources) && glob($sources . '/{pages,layouts}/*', GLOB_BRACE) !== []
            && !is_file(rtrim($directory, '/') . '/assets/build/.vite/manifest.json')) {
            $findings[] = ['level' => 'error', 'file' => 'resources/js', 'line' => 0, 'message' => 'Vue sources are not built: run "npm run build" (built output in assets/build/ ships with the package)'];
        }
        foreach ($manifest->stylesheets as $sheet) {
            if (!str_starts_with($sheet, '/') && AssetController::resolve(rtrim($directory, '/') . '/assets', $sheet) === null) {
                $findings[] = ['level' => 'error', 'file' => $type->manifestFile(), 'line' => 0, 'message' => "stylesheet assets/{$sheet} not found"];
            }
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                // vendor/ is code that runs like any other, so it is checked too
                static fn(\SplFileInfo $f): bool => !in_array($f->getFilename(), ['node_modules', '.git'], true),
            ),
        );

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1);

            $ext = strtolower($file->getExtension());
            if ($ext === 'phar') {
                $findings[] = ['level' => 'error', 'file' => $relative, 'line' => 0, 'message' => 'PHP archives (.phar) cannot be checked: ship their source instead'];
                continue;
            }
            // include() runs PHP from a file of any name, so anything that holds PHP code is PHP
            $php = in_array($ext, ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'inc'], true)
                || (!in_array($ext, ['js', 'mjs', 'vue', 'html', 'css', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'map'], true)
                    && $file->getSize() < 5_000_000 && preg_match('/<\?(php|=)/i', (string) file_get_contents($file->getPathname())) === 1);
            if ($php) {
                if (str_starts_with($relative, 'assets/')) {
                    $findings[] = ['level' => 'error', 'file' => $relative, 'line' => 0, 'message' => 'PHP files are not allowed in assets/'];
                }
                array_push($findings, ...$this->scanPhp($file->getPathname(), $relative, $manifest));
            } elseif (in_array($file->getExtension(), ['js', 'mjs', 'vue', 'html'], true)) {
                array_push($findings, ...$this->scanMarkup($file->getPathname(), $relative));
            }
        }

        return $findings;
    }

    /**
     * @return list<array{level: string, file: string, line: int, message: string}>
     */
    private function scanPhp(string $path, string $relative, Manifest $manifest): array
    {
        $findings = [];
        $tokens = token_get_all((string) file_get_contents($path));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '`') {
                $findings[] = $this->finding('error', $relative, $this->lineAt($tokens, $i), 'backtick shell execution');
                continue;
            }

            if (!is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if ($id === T_EVAL) {
                $findings[] = $this->finding('error', $relative, $line, 'eval() executes arbitrary code');
            } elseif ($id === T_STRING || $id === T_NAME_FULLY_QUALIFIED) {
                $name = strtolower(ltrim($text, '\\'));
                if (isset(self::DANGEROUS_FUNCTIONS[$name]) && $this->isFunctionCall($tokens, $i)) {
                    $level = in_array($name, ['eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'create_function', 'pcntl_exec'], true) ? 'error' : 'warning';
                    $findings[] = $this->finding($level, $relative, $line, "{$name}(): " . self::DANGEROUS_FUNCTIONS[$name]);
                }
            } elseif ($id === T_VARIABLE && in_array($text, self::SUPERGLOBALS, true)) {
                $findings[] = $this->finding('warning', $relative, $line, "{$text} accessed directly; use the Request object");
            } elseif (($id === T_INCLUDE || $id === T_REQUIRE || $id === T_INCLUDE_ONCE || $id === T_REQUIRE_ONCE)
                && $this->nextMeaningful($tokens, $i) === T_VARIABLE) {
                $findings[] = $this->finding('warning', $relative, $line, 'dynamic include of a variable path');
            } elseif ($id === T_NAMESPACE) {
                $ns = $this->namespaceAfter($tokens, $i);
                if ($ns !== '' && $manifest->namespace !== null && !str_starts_with($ns . '\\', $manifest->namespace)) {
                    $findings[] = $this->finding('error', $relative, $line, "namespace {$ns} is outside the declared {$manifest->namespace}");
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<array{level: string, file: string, line: int, message: string}>
     */
    private function scanMarkup(string $path, string $relative): array
    {
        $findings = [];

        foreach (file($path) ?: [] as $n => $lineText) {
            if (preg_match('/\bv-html\s*=/', $lineText) === 1) {
                $findings[] = $this->finding('warning', $relative, $n + 1, 'v-html renders raw HTML; use <SafeHtml> with server-sanitized content');
            }
            if (preg_match('/\b(eval|new\s+Function)\s*\(/', $lineText) === 1) {
                $findings[] = $this->finding('error', $relative, $n + 1, 'dynamic code execution in JavaScript');
            }
            if (!str_ends_with($relative, '.html') && preg_match('/^\s*template\s*:\s*[`\'"]|\bcompile\s*\(/', $lineText) === 1) {
                $findings[] = $this->finding('warning', $relative, $n + 1, 'string templates need the Vue compiler, which is not shipped (runtime-only, CSP-safe); use render functions or Vite-compiled .vue files');
            }
        }

        return $findings;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function isFunctionCall(array $tokens, int $i): bool
    {
        // Must be followed by "(" and not be a method/static call or a declaration.
        $next = $i + 1;
        while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            $next++;
        }
        if (($tokens[$next] ?? null) !== '(') {
            return false;
        }

        $prev = $i - 1;
        while (isset($tokens[$prev]) && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
            $prev--;
        }
        $p = $tokens[$prev] ?? null;

        return !(is_array($p) && in_array($p[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true));
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function nextMeaningful(array $tokens, int $i): int|string|null
    {
        for ($j = $i + 1; isset($tokens[$j]); $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            if ($tokens[$j] === '(') {
                continue;
            }
            return is_array($tokens[$j]) ? $tokens[$j][0] : $tokens[$j];
        }
        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function namespaceAfter(array $tokens, int $i): string
    {
        for ($j = $i + 1; isset($tokens[$j]); $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                return $tokens[$j][1];
            }
            if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                break;
            }
        }
        return '';
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function lineAt(array $tokens, int $i): int
    {
        for ($j = $i; $j >= 0; $j--) {
            if (is_array($tokens[$j])) {
                return $tokens[$j][2] + substr_count($tokens[$j][1], "\n");
            }
        }
        return 0;
    }

    /**
     * @return array{level: string, file: string, line: int, message: string}
     */
    private function finding(string $level, string $file, int $line, string $message): array
    {
        return ['level' => $level, 'file' => $file, 'line' => $line, 'message' => $message];
    }
}

<?php

declare(strict_types=1);

namespace Kestello\Framework\Console;

/**
 * Terminal output helper with ANSI colour support.
 */
final class Output
{
    private const string RESET  = "\033[0m";
    private const string BOLD   = "\033[1m";
    private const string RED    = "\033[31m";
    private const string GREEN  = "\033[32m";
    private const string YELLOW = "\033[33m";
    private const string CYAN   = "\033[36m";
    private const string GRAY   = "\033[90m";
    private const string WHITE  = "\033[97m";
    private const string BG_RED   = "\033[41m";
    private const string BG_GREEN = "\033[42m";

    /**
     * @param resource|null $stream where lines go (default STDOUT); a memory stream captures output
     * @param bool          $ansi   false strips colour codes (captured output, logs)
     */
    public function __construct(private readonly mixed $stream = null, private readonly bool $ansi = true) {}

    /**
     * Print a line to stdout.
     */
    public function line(string $text = ''): void
    {
        if (!$this->ansi) {
            $text = (string) preg_replace('/\e\[[0-9;]*m/', '', $text);
        }
        fwrite($this->stream ?? STDOUT, $text . PHP_EOL);
    }

    /**
     * Print an info message (cyan).
     */
    public function info(string $text): void
    {
        $this->line(self::CYAN . $text . self::RESET);
    }

    /**
     * Print a success message (green).
     */
    public function success(string $text): void
    {
        $this->line(self::GREEN . $text . self::RESET);
    }

    /**
     * Print a warning message (yellow).
     */
    public function warning(string $text): void
    {
        $this->line(self::YELLOW . $text . self::RESET);
    }

    /**
     * Print an error message (red).
     */
    public function error(string $text): void
    {
        $text = $this->ansi ? self::RED . $text . self::RESET : $text;
        fwrite($this->stream ?? STDERR, $text . PHP_EOL);
    }

    /**
     * Print a bold heading.
     */
    public function heading(string $text): void
    {
        $this->line(self::BOLD . self::WHITE . $text . self::RESET);
    }

    /**
     * Print a muted/gray line.
     */
    public function muted(string $text): void
    {
        $this->line(self::GRAY . $text . self::RESET);
    }

    /**
     * Print a labelled key-value pair.
     */
    public function label(string $label, string $value): void
    {
        $this->line(self::GREEN . "  {$label}" . self::RESET . "  {$value}");
    }

    /**
     * Print a newline.
     */
    public function newLine(int $count = 1): void
    {
        fwrite($this->stream ?? STDOUT, str_repeat(PHP_EOL, $count));
    }

    /**
     * Render a table with headers and rows.
     *
     * @param array<string>        $headers Column headers.
     * @param array<array<string>> $rows    Table rows.
     */
    public function table(array $headers, array $rows): void
    {
        // Calculate column widths
        $widths = array_map(fn(string $h): int => mb_strlen($h), $headers);

        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }

        // Build separator
        $separator = '+';
        foreach ($widths as $w) {
            $separator .= str_repeat('-', $w + 2) . '+';
        }

        // Print header
        $this->line($separator);
        $headerLine = '|';
        foreach (array_values($headers) as $i => $header) {
            $headerLine .= ' ' . self::BOLD . str_pad($header, $widths[$i]) . self::RESET . ' |';
        }
        $this->line($headerLine);
        $this->line($separator);

        // Print rows
        foreach ($rows as $row) {
            $rowLine = '|';
            foreach (array_values($row) as $i => $cell) {
                $rowLine .= ' ' . str_pad((string) $cell, $widths[$i]) . ' |';
            }
            $this->line($rowLine);
        }

        $this->line($separator);
    }

    /**
     * Ask for user input.
     */
    public function ask(string $question, string $default = ''): string
    {
        $prompt = self::CYAN . $question . self::RESET;
        if ($default !== '') {
            $prompt .= self::GRAY . " [{$default}]" . self::RESET;
        }
        $prompt .= ': ';

        fwrite(STDOUT, $prompt);
        $input = trim((string) fgets(STDIN));

        return $input !== '' ? $input : $default;
    }

    /**
     * Ask a yes/no confirmation.
     */
    public function confirm(string $question, bool $default = false): bool
    {
        $hint = $default ? 'Y/n' : 'y/N';
        $answer = strtolower($this->ask("{$question} [{$hint}]"));

        if ($answer === '') {
            return $default;
        }

        return in_array($answer, ['y', 'yes'], true);
    }
}

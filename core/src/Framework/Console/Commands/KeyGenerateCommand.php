<?php

declare(strict_types=1);

namespace Kestello\Framework\Console\Commands;

use Kestello\Framework\Console\Command;

/**
 * Generate a new application secret key. The old key moves to
 * app.previous_keys so secrets sealed with it can still be opened.
 */
final class KeyGenerateCommand extends Command
{
    protected string $name = 'key:generate';
    protected string $description = 'Generate a new app key; the old one stays in previous_keys for decryption (--forget-old to drop it)';

    public function __construct(
        private readonly string $basePath,
    ) {}

    public function handle(array $arguments, array $options): int
    {
        $configPath = $this->basePath . '/config/app.php';

        if (!is_file($configPath)) {
            $this->output->error('Config file not found: config/app.php');
            $this->output->muted('  Run the installer first, or create the config file manually.');
            return 1;
        }

        $config = require $configPath;
        if (!is_array($config)) {
            $this->output->error('config/app.php must return an array.');
            return 1;
        }

        $old = (string) ($config['secret_key'] ?? '');
        $previous = array_values(array_filter((array) ($config['previous_keys'] ?? []), 'is_string'));
        if ($old !== '' && !isset($options['forget-old'])) {
            array_unshift($previous, $old);
        }

        $config['secret_key'] = bin2hex(random_bytes(32));
        $config['previous_keys'] = array_values(array_unique(array_slice($previous, 0, 5)));

        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        $tmp = $configPath . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, $configPath)) {
            @unlink($tmp);
            $this->output->error('Could not write config/app.php.');
            return 1;
        }
        @chmod($configPath, 0640);

        $this->output->success('  New app key set. Recovery links made with the old key stop working.');
        if ($old !== '' && !isset($options['forget-old'])) {
            $this->output->muted('  The previous key is kept in previous_keys so stored secrets still open.');
            $this->output->muted('  Once secrets are re-saved with the new key, remove old entries from previous_keys.');
        }

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Kestello\Cms\Console;

use Kestello\Cms\Cms;
use Kestello\Cms\Hooks\Hook;
use Kestello\Cms\Hooks\HookReference;
use Kestello\Framework\Console\Command;

/**
 * hooks:list: the events and filters of core and the active plugins.
 *
 *   php bin/cms hooks:list                       every hook
 *   php bin/cms hooks:list --filters --owner=core
 *   php bin/cms hooks:list --deprecated          deprecated hooks and who still uses them
 *   php bin/cms hooks:list --markdown            the docs page
 *   php bin/cms hooks:list --json
 */
final class HooksListCommand extends Command
{
    protected string $name = 'hooks:list';
    protected string $description = 'List the events and filters of core and active plugins (--events, --filters, --owner=, --deprecated, --json, --markdown)';

    public function handle(array $arguments, array $options): int
    {
        $cms = Cms::instance() ?? throw new \RuntimeException('Not booted.');
        $kind = isset($options['events']) ? Hook::EVENT : (isset($options['filters']) ? Hook::FILTER : null);
        $owner = isset($options['owner']) ? (string) $options['owner'] : null;
        if ($owner !== null && $owner !== 'core' && !str_contains($owner, ':')) {
            $owner = 'plugin:' . $owner;
        }
        $hooks = array_values(array_filter($cms->hooks->all($kind), static fn(Hook $h) => ($owner === null || $h->owner === $owner)
            && (!isset($options['deprecated']) || $h->deprecated !== null)));

        if (isset($options['markdown'])) {
            $this->output->line(HookReference::markdown($hooks, $cms->version()));
            return 0;
        }
        if (isset($options['json'])) {
            $this->output->line((string) json_encode(['data' => array_map(static fn(Hook $h) => $h->toArray(), $hooks)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        if ($hooks === []) {
            $this->output->muted(isset($options['deprecated']) ? '  No deprecated hooks.' : '  No hooks match.');
        } else {
            $this->output->table(['Kind', 'Name', 'Since', 'Owner', 'Group'], array_map(static fn(Hook $h) => [
                $h->kind, ($h->kind === Hook::EVENT ? $h->shortName() : $h->name) . ($h->deprecated !== null ? ' (deprecated)' : ''),
                $h->since, $h->owner, $h->group,
            ], $hooks));
            $this->output->muted('  ' . count($hooks) . ' hooks. Details: --json, or the hook reference in the docs.');
        }

        $uses = $cms->hooks->deprecatedUses($cms->filters, $cms->events);
        if ($uses !== []) {
            $this->output->newLine();
            $this->output->warning('  Deprecated hooks in use:');
            foreach ($uses as $u) {
                $d = $u['hook']->deprecated;
                $this->output->line("    {$u['owner']} uses {$u['hook']->kind} {$u['as']}" . ($d['removal'] !== null ? ", removed in {$d['removal']}" : '') . ($d['use'] !== null ? "; use {$d['use']}" : ''));
            }
        }
        $unknown = $cms->hooks->undeclaredUses($cms->filters);
        if ($unknown !== [] && $owner === null) {
            $this->output->newLine();
            $this->output->warning('  Filters with callbacks that nothing declares (a typo, or a plugin that is not active):');
            foreach ($unknown as $name => $owners) {
                $this->output->line("    {$name}: " . implode(', ', $owners));
            }
        }

        return 0;
    }
}

<?php

declare(strict_types=1);

namespace Kestello\Cms\Forms;

use Kestello\Cms\Admin\AdminRegistry;
use Kestello\Cms\Blocks\BlockDefinition;
use Kestello\Cms\Cache\PageTags;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Extension\Provider;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Database\QueryBuilder;
use Kestello\Framework\Mail\Mailer;

/**
 * Core module: forms, the core/form block, submissions and their
 * retention.
 */
final class FormsModule extends Provider
{
    public function register(Container $container): void
    {
        $container->singleton(Forms::class, static fn() => new Forms(Cms::instance()));
        if (!$container->has('forms.mail')) {
            $container->singleton('forms.mail', static fn(): \Closure => static fn(array|string $to, string $subject, string $html, array $headers = [], ?string $text = null): bool => (new Mailer())->send($to, $subject, $html, $headers, $text));
        }
    }

    public function boot(Cms $cms): void
    {
        $cms->auth->roles->capability('forms.manage', 'Build forms and read what people send', grantTo: ['editor']);
        $cms->admin->page('forms', '@core/admin/forms/Index', 'Forms', 'forms.manage', ['label' => 'Forms', 'icon' => 'list-checks', 'group' => 'content', 'order' => 850]);
        $cms->admin->page('forms/{id}', '@core/admin/forms/Edit', 'Form', 'forms.manage', where: ['id' => AdminRegistry::ID]);
        $cms->admin->page('forms/{id}/submissions', '@core/admin/forms/Submissions', 'Submissions', 'forms.manage', where: ['id' => AdminRegistry::ID]);
        $cms->queue->job(SendFormNotification::class);
        $forms = $cms->container->make(Forms::class);
        $cms->schedule->call(static fn() => $forms->prune(), 'forms.prune-submissions')->daily();

        $cms->blocks->register(BlockDefinition::make('core/form')->label('Form')->category('widgets')->icon('list-checks')
            ->description('One of the forms from Content → Forms.')
            ->fields(static function (): array {
                try {
                    $choices = [];
                    foreach (QueryBuilder::table('forms')->orderBy('title')->get() as $row) {
                        $choices[(string) $row['id']] = (string) $row['title'];
                    }
                } catch (\Throwable) {
                    $choices = [];
                }

                return [Field::select('form', $choices ?: ['0' => 'No forms yet'])->label('Form')->required()->option('bindable', false)];
            })
            ->prepare(static function (array $node) use ($forms): array {
                $id = (int) ($node['props']['form'] ?? 0);
                PageTags::add('form:' . $id);
                $node['props']['definition'] = $id > 0 ? $forms->publicDefinition($id) : null;

                return $node;
            }));
    }
}

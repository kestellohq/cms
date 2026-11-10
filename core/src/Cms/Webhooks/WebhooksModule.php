<?php

declare(strict_types=1);

namespace Kestello\Cms\Webhooks;

use Kestello\Cms\Admin\AdminRegistry;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\Entry;
use Kestello\Cms\Events\EntryPublished;
use Kestello\Cms\Events\EntrySaved;
use Kestello\Cms\Events\EntrySubmitted;
use Kestello\Cms\Events\EntryTrashed;
use Kestello\Cms\Events\FormSubmitted;
use Kestello\Cms\Events\UserRegistered;
use Kestello\Cms\Extension\Provider;
use Kestello\Framework\Database\Orm\Caster;
use Kestello\Framework\Database\QueryBuilder;
use Throwable;

/**
 * Core module: the core webhook events, sending them when the matching
 * core events happen, the retry and clean-up tasks, and System → Webhooks.
 */
final class WebhooksModule extends Provider
{
    public function boot(Cms $cms): void
    {
        $w = $cms->webhooks;
        $w->event('entry.created', 'An entry was created', 'Content', 'Any content type, in any status. data: the entry.');
        $w->event('entry.updated', 'An entry was saved', 'Content', 'Every later save, in any status. data: the entry.');
        $w->event('entry.published', 'An entry went live', 'Content', 'Published now, or a scheduled entry reached its time. data: the entry.');
        $w->event('entry.trashed', 'An entry was moved to the trash', 'Content', 'data: the entry.');
        $w->event('entry.submitted', 'An entry was submitted for review', 'Content', 'data: the entry.');
        $w->event('form.submitted', 'A form was submitted', 'Forms', 'data: the form, the stored submission id and the answers.');
        $w->event('user.registered', 'A person got an account', 'People', 'data: id, username, email, role and how the account was made.');
        $w->event('webhook.ping', 'Test message', 'Webhooks', 'Sent by "Send a test" only.');

        $cms->queue->job(DeliverWebhook::class);
        $cms->notifications->type(WebhookDisabledNotification::class, 'Webhook turned off', 'When a webhook kept failing and was turned off', capability: 'system.manage'); // sent to Administrators::ids()

        $send = static function (string $event, \Closure $data) use ($cms): void {
            try {
                $payload = $data();
                if ($payload !== null) {
                    $cms->webhooks->dispatch($event, $payload);
                }
            } catch (Throwable $e) {
                // A webhook problem never breaks the save, submission or sign-up that caused it.
                error_log("[kestello] webhook {$event}: " . $e->getMessage());
            }
        };
        $cms->events->on(EntrySaved::class, static fn(EntrySaved $e) => $send($e->created ? 'entry.created' : 'entry.updated', static fn() => self::entry($cms, $e->entryId)));
        $cms->events->on(EntryPublished::class, static fn(EntryPublished $e) => $send('entry.published', static fn() => self::entry($cms, $e->entryId)));
        $cms->events->on(EntryTrashed::class, static fn(EntryTrashed $e) => $send('entry.trashed', static fn() => self::entry($cms, $e->entryId)));
        $cms->events->on(EntrySubmitted::class, static fn(EntrySubmitted $e) => $send('entry.submitted', static fn() => self::entry($cms, $e->entryId)));
        $cms->events->on(FormSubmitted::class, static fn(FormSubmitted $e) => $send('form.submitted', static fn() => [
            'form' => ['id' => $e->formId, 'title' => (string) (QueryBuilder::table('forms')->where('id', $e->formId)->value('title') ?? '')],
            'submission_id' => $e->submissionId,
            'answers' => $e->data,
        ]));
        $cms->events->on(UserRegistered::class, static fn(UserRegistered $e) => $send('user.registered', static function () use ($e): ?array {
            $u = QueryBuilder::table('users')->where('id', $e->userId)->first();

            return $u === null ? null : ['id' => (int) $u['id'], 'username' => (string) $u['username'], 'email' => (string) $u['email'], 'role' => (string) $u['role'], 'via' => $e->via];
        }));

        $cms->schedule->call(static fn(): string => $cms->webhooks->retryDue() . ' webhook deliveries queued again.', 'webhooks.retry')
            ->everyMinute()->description('Queues webhook deliveries whose next attempt is due.');
        $cms->schedule->call(static fn(): string => $cms->webhooks->prune() . ' old webhook deliveries.', 'webhooks.prune')
            ->dailyAt('03:40')->description('Deletes webhook deliveries older than 30 days.');

        $cms->admin->page('system/webhooks', '@core/admin/system/webhooks/Index', 'Webhooks', 'webhooks.manage', ['label' => 'Webhooks', 'icon' => 'webhooks-logo', 'group' => 'system', 'order' => 50]);
        $cms->admin->page('system/webhooks/{id}', '@core/admin/system/webhooks/Edit', 'Webhook', 'webhooks.manage', where: ['id' => AdminRegistry::ID]);
    }

    /**
     * An entry as webhooks send it: what a receiver needs to decide what to
     * do, and the address of the API resource for the rest.
     *
     * @return array<string, mixed>|null
     */
    public static function entry(Cms $cms, int $id): ?array
    {
        $entry = Entry::query()->withTrashed()->with('author')->find($id);
        $type = $entry === null ? null : $cms->content->get((string) $entry->type);
        if ($entry === null || $type === null) {
            return null;
        }
        $path = $entry->path($type);
        $site = rtrim((string) $cms->config->get('app.url', ''), '/');

        return [
            'id' => (int) $entry->getKey(),
            'type' => $type->name,
            'title' => (string) $entry->title,
            'slug' => (string) $entry->slug,
            'status' => (string) $entry->status,
            'path' => $path,
            'url' => $path !== null && $site !== '' && $entry->isPublished() ? $site . $path : null,
            'api' => '/api/v1/content/' . rawurlencode($type->name) . '/entries/' . $entry->getKey(),
            'author' => $entry->author === null ? null : ['id' => (int) $entry->author->getKey(), 'username' => (string) $entry->author->username],
            'published_at' => Caster::present($entry->published_at),
            'updated_at' => Caster::present($entry->updated_at),
            'trashed_at' => Caster::present($entry->deleted_at),
        ];
    }
}

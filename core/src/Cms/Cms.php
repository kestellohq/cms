<?php

declare(strict_types=1);

namespace Kestello\Cms;

use Kestello\Cms\Admin\AdminRegistry;
use Kestello\Cms\Api\ApiRegistry;
use Kestello\Cms\Audit\AuditLog;
use Kestello\Cms\Auth\AuthRegistry;
use Kestello\Cms\Extension\CommandRegistry;
use Kestello\Cms\Extension\ExtensionManager;
use Kestello\Cms\Health\SiteHealth;
use Kestello\Cms\Notifications\Notifier;
use Kestello\Cms\Platform\DriverRegistry;
use Kestello\Cms\Queue\JobQueue;
use Kestello\Cms\Schedule\Schedule;
use Kestello\Cms\View\Pages;
use Kestello\Framework\Core\Config;
use Kestello\Framework\Core\Container;
use Kestello\Framework\Core\Paths;
use Kestello\Framework\Core\Router;
use Kestello\Framework\Events\EventDispatcher;
use Kestello\Framework\Events\Filters;
use Kestello\Framework\Storage\StorageManager;
use Kestello\Kernel;

/**
 * The extension API: what providers receive in boot().
 *
 *   $cms->events      typed events
 *   $cms->filters     named value filters
 *   $cms->routes      the router
 *   $cms->pages       data shared with every Vue page (usePage().props)
 *   $cms->console     CLI commands
 *   $cms->auth        guards, roles and capabilities
 *   $cms->api         API endpoints, scopes and resource extensions
 *   $cms->drivers     platform drivers (cache, sessions, queue, storage…)
 *   $cms->queue       background jobs (push, register job classes)
 *   $cms->schedule    recurring tasks (cron)
 *   $cms->storage     file disks (media, public, private, backups, plugin disks)
 *   $cms->notifications  user notifications (inbox, mail, plugin channels)
 *   $cms->health      Site Health checks
 *   $cms->admin       admin pages and menu
 *   $cms->models      ORM: model events, extensions, morph map
 *   $cms->content     content types, taxonomies and field types
 *   $cms->menus       menu locations (themes declare them)
 *   $cms->audit       the audit log (security-relevant actions)
 *   $cms->hooks       the declared events and filters (hooks:list, the hook reference)
 *   $cms->webhooks    outgoing webhooks: offer events, send them
 *   $cms->container   service container (bind/rebind contracts)
 *
 * @api
 */
final class Cms
{
    private static ?self $instance = null;

    private ?ExtensionManager $extensions = null;

    /** ORM: model events, model extensions, the morph map. */
    public readonly \Kestello\Framework\Database\Orm\ModelRegistry $models;

    /** Site search. */
    public readonly \Kestello\Cms\Search\SearchManager $search;

    public function __construct(
        public readonly Container $container,
        public readonly Paths $paths,
        public readonly Config $config,
        public readonly Router $routes,
        public readonly EventDispatcher $events,
        public readonly Filters $filters,
        public readonly Pages $pages,
        public readonly CommandRegistry $console,
        public readonly AuthRegistry $auth,
        public readonly ApiRegistry $api,
        public readonly DriverRegistry $drivers,
        public readonly AuditLog $audit = new AuditLog(),
        public readonly JobQueue $queue = new JobQueue(),
        public readonly Schedule $schedule = new Schedule(),
        public readonly StorageManager $storage = new StorageManager(),
        public readonly Notifier $notifications = new Notifier(),
        public readonly SiteHealth $health = new SiteHealth(),
        public readonly AdminRegistry $admin = new AdminRegistry(),
        public readonly \Kestello\Cms\Content\ContentRegistry $content = new \Kestello\Cms\Content\ContentRegistry(),
        public readonly \Kestello\Cms\Menus\MenuRegistry $menus = new \Kestello\Cms\Menus\MenuRegistry(),
        public readonly \Kestello\Cms\Media\MediaRegistry $media = new \Kestello\Cms\Media\MediaRegistry(),
        public readonly \Kestello\Cms\Blocks\BlockRegistry $blocks = new \Kestello\Cms\Blocks\BlockRegistry(),
        public readonly \Kestello\Cms\Hooks\HookRegistry $hooks = new \Kestello\Cms\Hooks\HookRegistry(),
        public readonly \Kestello\Cms\Webhooks\Webhooks $webhooks = new \Kestello\Cms\Webhooks\Webhooks(),
    ) {
        $this->hooks->connect($filters);
        \Kestello\Framework\Core\ExecutionContext::run('core', fn() => \Kestello\Cms\Hooks\CoreHooks::declare($this->hooks));
        $this->models = \Kestello\Framework\Database\Model::registry();
        $this->search = new \Kestello\Cms\Search\SearchManager($this);
    }

    public static function setInstance(?self $cms): void
    {
        self::$instance = $cms;
    }

    /**
     * The booted instance, or null before boot (installer, unit tests).
     */
    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function version(): string
    {
        return Kernel::version();
    }

    /** @internal */
    public function setExtensions(ExtensionManager $extensions): void
    {
        $this->extensions = $extensions;
    }

    public function extensions(): ExtensionManager
    {
        return $this->extensions ?? throw new \LogicException('Extensions are not booted.');
    }
}

<?php

declare(strict_types=1);

namespace Plugins\Notebook;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Cms\Content\Entries;
use Kestello\Cms\Content\Field;
use Kestello\Cms\Content\Terms;
use Kestello\Cms\Extension\PluginProvider;

final class NotebookProvider extends PluginProvider
{
    public const string PAGE = 'notebook-page';
    public const string TOPIC = 'notebook-topic';

    public function boot(Cms $cms): void
    {
        $cms->content->type(self::PAGE)->label('Notebook page', 'Notebook pages')->path('/notebook')
            ->fields([Field::text('code')->label('Code')->required()->max(20)->indexed()]);
        $cms->content->taxonomy(self::TOPIC)->label('Topic', 'Topics')->for(self::PAGE);
        $cms->auth->roles->capability('notebook.write', 'Add notes to notebook pages', grantTo: ['admin']);
        $cms->api->scope('notebook:write', 'Add notes');
        $cms->api->endpoint(AddNote::class);
        $cms->admin->settings('general', 'Notebook', Schema::object(['heading' => Schema::string()->default('Notes')]), labels: ['heading' => 'Heading']);
    }

    /** Its content goes with it; its table goes with its migrations; core removes its settings. */
    public function uninstall(Cms $cms): void
    {
        $cms->container->make(Entries::class)->purgeType(self::PAGE);
        $cms->container->make(Terms::class)->purgeTaxonomy(self::TOPIC);
    }
}

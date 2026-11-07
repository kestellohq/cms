<?php

declare(strict_types=1);

use Kestello\Framework\Database\Migration;
use Kestello\Framework\Database\QueryBuilder;

/**
 * The admin's icons moved from Bootstrap Icons to Phosphor. Icon names stored
 * in the database (content types built in the admin, notifications) are
 * renamed; names Phosphor shares with Bootstrap Icons stay as they are.
 */
return new class extends Migration {
    private const array RENAMED = [
        'arrow-bar-left' => 'arrow-line-left',
        'arrow-bar-right' => 'arrow-line-right',
        'arrow-counterclockwise' => 'arrow-counter-clockwise',
        'arrow-repeat' => 'arrows-clockwise',
        'bookmark-star' => 'star',
        'box-arrow-in-down' => 'download-simple',
        'box-arrow-in-right' => 'sign-in',
        'box-arrow-right' => 'sign-out',
        'box-arrow-up-right' => 'arrow-square-out',
        'box-seam' => 'package',
        'calendar-event' => 'calendar-dots',
        'card-text' => 'article',
        'chat-quote' => 'quotes',
        'check-circle-fill' => 'check-circle',
        'check-lg' => 'check',
        'check2' => 'check',
        'check2-circle' => 'check-circle',
        'chevron-down' => 'caret-down',
        'chevron-left' => 'caret-left',
        'chevron-right' => 'caret-right',
        'chevron-up' => 'caret-up',
        'clipboard-plus' => 'clipboard-text',
        'clock-history' => 'clock-counter-clockwise',
        'code-slash' => 'code',
        'collection' => 'cards',
        'columns-gap' => 'columns',
        'cup-hot' => 'coffee',
        'diagram-3' => 'tree-structure',
        'display' => 'monitor',
        'emoji-smile' => 'smiley',
        'exclamation-circle' => 'warning-circle',
        'exclamation-octagon' => 'warning-octagon',
        'exclamation-octagon-fill' => 'warning-octagon',
        'exclamation-triangle' => 'warning',
        'exclamation-triangle-fill' => 'warning',
        'file-earmark' => 'file',
        'file-earmark-excel' => 'file-xls',
        'file-earmark-music' => 'file-audio',
        'file-earmark-pdf' => 'file-pdf',
        'file-earmark-play' => 'file-video',
        'file-earmark-ppt' => 'file-ppt',
        'file-earmark-slides' => 'file-ppt',
        'file-earmark-spreadsheet' => 'file-csv',
        'file-earmark-text' => 'file-text',
        'file-earmark-word' => 'file-doc',
        'file-earmark-zip' => 'file-zip',
        'file-richtext' => 'article',
        'film' => 'film-strip',
        'floppy' => 'floppy-disk',
        'folder2' => 'folder',
        'geo-alt' => 'map-pin',
        'github' => 'github-logo',
        'google' => 'google-logo',
        'graph-up' => 'chart-line-up',
        'grid-1x2' => 'squares-four',
        'hand-index' => 'hand-pointing',
        'hdd' => 'hard-drive',
        'hdd-network' => 'hard-drives',
        'hdd-stack' => 'hard-drives',
        'heart-pulse' => 'heartbeat',
        'hourglass-split' => 'hourglass-medium',
        'inbox' => 'tray',
        'info-circle' => 'info',
        'info-circle-fill' => 'info',
        'journal-text' => 'notebook',
        'layout-split' => 'columns',
        'layout-text-window' => 'layout',
        'layout-three-columns' => 'columns',
        'layout-wtf' => 'squares-four',
        'lightning-charge' => 'lightning',
        'link-45deg' => 'link',
        'list-nested' => 'tree-view',
        'list-ol' => 'list-numbers',
        'list-ul' => 'list-bullets',
        'mortarboard' => 'graduation-cap',
        'music-note-beamed' => 'music-notes',
        'patch-check' => 'seal-check',
        'patch-exclamation' => 'seal-warning',
        'patch-question' => 'seal-question',
        'pencil-square' => 'note-pencil',
        'people' => 'users',
        'person' => 'user',
        'person-badge' => 'identification-badge',
        'person-circle' => 'user-circle',
        'person-plus' => 'user-plus',
        'phone' => 'device-mobile',
        'play-btn' => 'play-circle',
        'play-fill' => 'play',
        'plus-lg' => 'plus',
        'puzzle' => 'puzzle-piece',
        'question-circle' => 'question',
        'quote' => 'quotes',
        'save' => 'floppy-disk',
        'search' => 'magnifying-glass',
        'send' => 'paper-plane-tilt',
        'server' => 'hard-drives',
        'shield-exclamation' => 'shield-warning',
        'shield-lock' => 'shield-check',
        'shop' => 'storefront',
        'signpost-split' => 'signpost',
        'slash-circle' => 'prohibit',
        'speedometer2' => 'gauge',
        'tablet' => 'device-tablet',
        'tags' => 'tag',
        'text-paragraph' => 'text-align-left',
        'tools' => 'wrench',
        'trash3' => 'trash',
        'type' => 'text-t',
        'type-bold' => 'text-b',
        'type-h1' => 'text-h-one',
        'type-h2' => 'text-h-two',
        'type-h3' => 'text-h-three',
        'type-italic' => 'text-italic',
        'ui-checks' => 'list-checks',
        'x-lg' => 'x',
        'x-octagon' => 'x-circle',
    ];

    public function up(): void
    {
        foreach (QueryBuilder::table('content_models')->select('id', 'definition')->get() as $row) {
            $def = json_decode((string) $row['definition'], true);
            if (is_array($def) && isset($def['icon'], self::RENAMED[$def['icon']])) {
                $def['icon'] = self::RENAMED[$def['icon']];
                QueryBuilder::table('content_models')->where('id', $row['id'])->update(['definition' => json_encode($def, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            }
        }
        foreach (QueryBuilder::table('notifications')->select('id', 'data')->whereLike('data', '%"icon"%')->get() as $row) {
            $data = json_decode((string) $row['data'], true);
            if (is_array($data) && is_string($data['icon'] ?? null) && isset(self::RENAMED[$data['icon']])) {
                $data['icon'] = self::RENAMED[$data['icon']];
                QueryBuilder::table('notifications')->where('id', $row['id'])->update(['data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    public function down(): void
    {
        // Phosphor names stay: Bootstrap Icons no longer ship.
    }
};

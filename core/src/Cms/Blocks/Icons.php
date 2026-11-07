<?php

declare(strict_types=1);

namespace Kestello\Cms\Blocks;

/**
 * The icons blocks can show, by the names they store. Their drawings are
 * resources/js/shared/icons.js and social-icons.js (generated from Phosphor);
 * IconsTest keeps these lists and those files in step.
 *
 * @internal CoreBlocks
 */
final class Icons
{
    /** Icons for content (icon lists): name => label. */
    public const array CONTENT = [
        'check' => 'Tick', 'check-circle' => 'Tick in a circle', 'x' => 'Cross', 'x-circle' => 'Cross in a circle', 'star' => 'Star', 'heart' => 'Heart',
        'arrow-right' => 'Arrow', 'caret-right' => 'Chevron', 'dot' => 'Dot', 'plus' => 'Plus', 'minus' => 'Minus', 'info' => 'Information',
        'warning' => 'Warning', 'question' => 'Question', 'lightning' => 'Lightning', 'leaf' => 'Leaf', 'sun' => 'Sun', 'moon' => 'Moon',
        'truck' => 'Delivery', 'shield' => 'Shield', 'lock' => 'Lock', 'gift' => 'Gift', 'globe' => 'Globe', 'user' => 'Person', 'users' => 'People',
        'chat' => 'Chat', 'phone' => 'Phone', 'envelope' => 'Email', 'map-pin' => 'Location', 'clock' => 'Clock', 'calendar' => 'Calendar',
        'house' => 'House', 'coffee' => 'Coffee', 'thumbs-up' => 'Thumbs up', 'trophy' => 'Trophy', 'rocket' => 'Rocket', 'wrench' => 'Wrench',
        'credit-card' => 'Card payment', 'tag' => 'Price tag', 'link' => 'Link', 'download' => 'Download', 'play' => 'Play', 'camera' => 'Camera',
        'music' => 'Music', 'book' => 'Book', 'graduation' => 'Graduation', 'briefcase' => 'Briefcase', 'store' => 'Shop', 'percent' => 'Percent',
        'package' => 'Parcel', 'recycle' => 'Recycling', 'wifi' => 'Wi-Fi', 'car' => 'Car', 'bicycle' => 'Bicycle', 'airplane' => 'Aeroplane',
        'paw' => 'Paw', 'smiley' => 'Smile',
    ];

    /** Social networks and contact links: name => label (also what screen readers hear). */
    public const array SOCIAL = [
        'facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'tiktok' => 'TikTok',
        'threads' => 'Threads', 'bluesky' => 'Bluesky', 'mastodon' => 'Mastodon', 'pinterest' => 'Pinterest', 'github' => 'GitHub',
        'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'discord' => 'Discord', 'reddit' => 'Reddit', 'twitch' => 'Twitch',
        'spotify' => 'Spotify', 'soundcloud' => 'SoundCloud', 'dribbble' => 'Dribbble', 'behance' => 'Behance',
        'email' => 'Email', 'rss' => 'News feed', 'website' => 'Website',
    ];
}

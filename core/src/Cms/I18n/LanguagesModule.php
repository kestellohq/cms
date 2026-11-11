<?php

declare(strict_types=1);

namespace Kestello\Cms\I18n;

use Kestello\Cms\Cms;
use Kestello\Cms\Extension\Provider;

/**
 * Core module: a site in several languages. Registered before the page
 * cache, so every later step sees the language of the address.
 */
final class LanguagesModule extends Provider
{
    public function boot(Cms $cms): void
    {
        $cms->routes->pushGlobalMiddleware(new LanguagePrefix());
        // the page in the site's other languages, for search engines (a plugin's filter can add or replace them)
        $cms->filters->add('seo.alternates', static fn(array $alternates): array => [...PageLanguages::alternates(), ...$alternates]);
    }
}

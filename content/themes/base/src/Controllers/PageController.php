<?php

declare(strict_types=1);

namespace Themes\Base\Controllers;

use Kestello\Cms\View\Page;
use Kestello\Framework\Core\Response;
use Kestello\Kernel;

final class PageController
{
    public function home(): Response
    {
        return Page::render('Home', ['title' => 'Home']);
    }

    public function about(): Response
    {
        return Page::render('About', ['title' => 'About', 'kestelloVersion' => Kernel::version()]);
    }
}

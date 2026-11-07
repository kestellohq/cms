<?php

declare(strict_types=1);

namespace Kestello\Contracts\Forms;

/**
 * A captcha service for forms (none ships with core). A plugin binds
 * one in the container; forms that turn the captcha on then show its
 * widget and refuse submissions it doesn't verify.
 *
 * @api
 */
interface Captcha
{
    /**
     * What the form block needs to show the widget: the script to load,
     * the site key and the name of the field its answer arrives in.
     *
     * @return array{script: string, site_key: string, name: string}
     */
    public function widget(): array;

    /** Whether the answer the widget put in the form is valid for this visitor. */
    public function verify(string $response, string $ip): bool;
}

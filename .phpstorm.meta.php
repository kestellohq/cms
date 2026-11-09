<?php

// Kestello: hints for PhpStorm. Made by `bin/cms ide:helpers`; edit the code, not this file.

namespace PHPSTORM_META {

    // $container->make(Foo::class) returns a Foo
    override(\Kestello\Framework\Core\Container::make(0), map(['' => '@']));

    registerArgumentsSet('kestello_capabilities', 'read', 'admin.access', 'content.access', 'profile.manage', 'users.read', 'users.manage', 'roles.manage', 'menus.manage', 'media.upload', 'media.manage', 'settings.manage', 'audit.read', 'security.manage', 'webhooks.manage', 'system.manage', 'fleet.manage');
    registerArgumentsSet('kestello_widget_areas', 'dashboard');
    registerArgumentsSet('kestello_slots', 'users.edit.panels', 'profile.tabs');
    registerArgumentsSet('kestello_columns', 'users.list', 'entries.list');
    registerArgumentsSet('kestello_replaceable', 'users.avatar', 'media.picker', 'editor.richtext', 'editor.code', 'auth.login-form');

    expectedArguments(\Kestello\Contracts\Auth\Principal::can(), 0, argumentsSet('kestello_capabilities'));
    expectedArguments(\Kestello\Cms\Admin\AdminRegistry::page(), 3, argumentsSet('kestello_capabilities'));
    expectedArguments(\Kestello\Cms\Admin\AdminRegistry::link(), 6, argumentsSet('kestello_capabilities'));
    expectedArguments(\Kestello\Cms\Admin\AdminRegistry::widget(), 0, argumentsSet('kestello_widget_areas'));
    expectedArguments(\Kestello\Cms\Admin\AdminRegistry::widget(), 4, argumentsSet('kestello_capabilities'));
    expectedArguments(\Kestello\Cms\Admin\AdminRegistry::widget(), 3, 'quarter', 'half', 'full');
    expectedArguments(\Kestello\Cms\Admin\AdminRegistry::slot(), 0, argumentsSet('kestello_slots'));
    expectedArguments(\Kestello\Cms\Admin\AdminRegistry::slot(), 2, argumentsSet('kestello_capabilities'));
    expectedArguments(\Kestello\Cms\Admin\AdminRegistry::column(), 0, argumentsSet('kestello_columns'));
    expectedArguments(\Kestello\Cms\Admin\AdminRegistry::column(), 4, argumentsSet('kestello_capabilities'));
    expectedArguments(\Kestello\Cms\Admin\AdminRegistry::replace(), 0, argumentsSet('kestello_replaceable'));
}

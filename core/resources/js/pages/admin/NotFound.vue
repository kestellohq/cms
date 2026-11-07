<script setup>
import { __ } from '@kestello/sdk';
import { KButton, KCard, KEmpty, KPageHeader } from '@kestello/ui';

// An admin address that leads nowhere (AdminPageController::notFound): the menu stays, to find the way.
defineOptions({ layout: 'Admin' });
</script>

<template>
    <KPageHeader :title="__('Page not found')" />
    <KCard>
        <KEmpty icon="compass" :title="__('There is no admin page at this address')">
            <p>{{ __('The link may be mistyped, or the plugin that added the page may be turned off.') }}</p>
            <KButton href="/admin" variant="primary">{{ __('Go to the overview') }}</KButton>
        </KEmpty>
    </KCard>
</template>

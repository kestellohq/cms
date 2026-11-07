<script setup>
import { __ } from '@kestello/sdk';
import KModal from '../KModal.vue';
import { useBuilder } from './context.js';
import { keyLabel, shortcutGroups } from './shortcuts.js';

/** Every shortcut, written for this computer (? opens it). */
const { shortcutsOpen } = useBuilder();
const groups = shortcutGroups(__);
</script>

<template>
    <KModal :open="shortcutsOpen" :title="__('Keyboard shortcuts')" size="lg" @close="shortcutsOpen = false">
        <div class="k-builder-keys">
            <section v-for="[group, items] in groups" :key="group">
                <h3>{{ group }}</h3>
                <dl>
                    <div v-for="[what, keys] in items" :key="what">
                        <dt>{{ what }}</dt>
                        <dd>
                            <kbd>{{ keyLabel(keys) }}</kbd>
                        </dd>
                    </div>
                </dl>
            </section>
        </div>
    </KModal>
</template>

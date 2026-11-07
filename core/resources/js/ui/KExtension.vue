<script setup>
import { onErrorCaptured, ref, shallowRef, watch } from 'vue';
import { useComponentLoader } from '@kestello/app';
import { __ } from '@kestello/sdk';

/**
 * Renders a component a plugin registered (widget, slot, cell, settings
 * tab, replacement) by its registry name. A plugin that fails to load or
 * throws while rendering is contained here: the rest of the screen keeps
 * working and the failure is named.
 *
 *   <KExtension name="@acme/admin/SalesWidget" owner="acme" :context="{ user }" />
 *
 * Every other attribute is passed to the component as props.
 */
defineOptions({ inheritAttrs: false });
const props = defineProps({
    name: { type: String, required: true },
    owner: { type: String, default: '' },
    inline: Boolean, // table cells: a quiet dash instead of a panel
});

const load = useComponentLoader();
const component = shallowRef(null);
const failed = ref(false);

watch(
    () => props.name,
    async (name) => {
        failed.value = false;
        component.value = null;
        try {
            component.value = await load(name);
        } catch (error) {
            console.error(`[kestello] ${props.owner || name}: ${error.message}`, error);
            failed.value = true;
        }
    },
    { immediate: true },
);

onErrorCaptured((error) => {
    console.error(`[kestello] ${props.owner || props.name} failed while rendering`, error);
    failed.value = true;
    return false;
});
</script>

<template>
    <template v-if="failed">
        <span v-if="inline" class="k-muted" :title="__('{owner} could not show this', { owner: owner || name })">—</span>
        <p v-else class="k-extension-failed" role="status">
            {{ __('{owner} could not show this part.', { owner: owner || __('A plugin') }) }}
            <span class="k-muted">{{ __('The rest of the screen is unaffected. Details are in the browser console.') }}</span>
        </p>
    </template>
    <component :is="component" v-else-if="component" v-bind="$attrs" />
</template>

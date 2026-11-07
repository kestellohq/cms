<script setup>
import { computed, inject } from 'vue';
import { __ } from '@kestello/sdk';
import KButton from './KButton.vue';
import KBlockSettings from './KBlockSettings.vue';
import KIcon from './KIcon.vue';
import KBlockList from './KBlockList.vue';

/**
 * One level of a block tree in the editor (it nests itself for children).
 * Lives inside the "@core/fields/Blocks" field, which provides the block
 * catalog, the open/closed state and the inserter.
 */
const blocks = defineModel({ type: Array, default: () => [] });
const props = defineProps({
    parent: { type: Object, default: null }, // the block definition holding this list (null: top level)
    allowed: { type: Array, default: null }, // top level only: the field's allowed types
    disabled: Boolean,
    trail: { type: String, default: '' }, // "Columns › Column 2", for labels
});
const editor = inject('kestello.blocks');

const list = computed(() => (Array.isArray(blocks.value) ? blocks.value : []));
const defOf = (node) => editor.catalog.value.get(node?.type) ?? null;
const name = (node, i) => `${defOf(node)?.label ?? node?.type} ${i + 1}`;

const plain = (html) =>
    String(html ?? '')
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
function summary(node) {
    const def = defOf(node);
    if (!def) return __('Not available: its plugin may be off. It is kept, but not shown on the site.');
    const p = node.props ?? {};
    for (const f of def.fields) {
        const v = p[f.name];
        if (v && typeof v === 'object' && typeof v.bind === 'string') return __('Shows {data}', { data: editor.bindingLabel(v.bind) });
        if (['text', 'textarea', 'rich_text', 'url', 'link'].includes(f.type) && v) return plain(v).slice(0, 90);
    }
    const choice = def.fields.find((f) => f.type === 'select' && p[f.name]);
    if (choice) return choice.options.choices?.find((c) => c.value === p[choice.name])?.label ?? '';
    return '';
}
function visibilityNote(node) {
    const v = node.visibility;
    if (!v) return '';
    const parts = [];
    if (v.auth === 'guest') parts.push(__('guests only'));
    if (v.auth === 'user') parts.push(__('signed-in only'));
    if (v.roles?.length) parts.push(v.roles.join(', '));
    if (v.from || v.until) parts.push(__('scheduled'));
    if (v.hide?.length) parts.push(__('hidden on {screens}', { screens: v.hide.join(', ') }));
    return parts.join(', ');
}

const replace = (next) => {
    blocks.value = next;
};
function update(i, patch) {
    replace(list.value.map((n, j) => (j === i ? { ...n, ...patch } : n)));
}
function move(i, delta, event) {
    const j = i + delta;
    if (j < 0 || j >= list.value.length) return;
    const next = [...list.value];
    [next[i], next[j]] = [next[j], next[i]];
    replace(next);
    // keep keyboard focus on the moved block's handle
    const root = event?.currentTarget?.closest('.k-blocks-ed__list');
    if (root) requestAnimationFrame(() => root.querySelectorAll(':scope > .k-blocks-ed__item > .k-blocks-ed__row .k-blocks-ed__toggle')[j]?.focus());
}
function duplicate(i) {
    const copy = editor.reid(JSON.parse(JSON.stringify(list.value[i])));
    const next = [...list.value];
    next.splice(i + 1, 0, copy);
    replace(next);
    editor.open(copy.id);
}
function remove(i) {
    replace(list.value.filter((_, j) => j !== i));
}
function insert(at) {
    editor.insert({ parent: props.parent, allowed: props.allowed, where: props.trail }, (node) => {
        const next = [...list.value];
        next.splice(at, 0, node);
        replace(next);
        editor.open(node.id);
    });
}
function onRowKey(event, i) {
    if (!event.altKey || locked(list.value[i], 'move')) return;
    if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(i, -1, event);
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(i, 1, event);
    }
}

// layouts can lock blocks: editors can't move, remove or change them (people who design can, in the builder)
const locked = (node, what) => (node.lock ?? []).includes(what);

const addLabel = computed(() => (props.parent ? __('Add to {block}', { block: props.parent.label }) : __('Add block')));
</script>

<template>
    <div class="k-blocks-ed__level">
        <ol v-if="list.length" class="k-blocks-ed__list">
            <li
                v-for="(node, i) in list"
                :key="node.id ?? i"
                class="k-blocks-ed__item"
                :class="{ 'is-open': editor.isOpen(node.id), 'is-missing': !defOf(node) }"
            >
                <div class="k-blocks-ed__row" @keydown="onRowKey($event, i)">
                    <button
                        type="button"
                        class="k-blocks-ed__toggle"
                        :aria-expanded="defOf(node) ? String(editor.isOpen(node.id)) : undefined"
                        :disabled="!defOf(node)"
                        :aria-label="`${name(node, i)}${summary(node) ? `: ${summary(node)}` : ''}`"
                        @click="editor.toggle(node.id)"
                    >
                        <KIcon :name="defOf(node)?.icon ?? 'warning'" class="k-blocks-ed__icon" />
                        <span class="k-blocks-ed__label">{{ defOf(node)?.label ?? node.type }}</span>
                        <span class="k-blocks-ed__summary">{{ summary(node) }}</span>
                        <span v-if="visibilityNote(node)" class="k-blocks-ed__flag"><KIcon name="eye-slash" />{{ visibilityNote(node) }}</span>
                        <KIcon v-if="defOf(node)" :name="editor.isOpen(node.id) ? 'caret-up' : 'caret-down'" class="k-blocks-ed__chevron" />
                    </button>
                    <span v-if="!disabled" class="k-blocks-ed__tools">
                        <KIcon v-if="node.lock?.length" name="lock" class="k-blocks-ed__lock" :title="__('Locked by the layout')" />
                        <KButton
                            size="sm"
                            variant="ghost"
                            icon="arrow-up"
                            :disabled="i === 0 || locked(node, 'move')"
                            :aria-label="__('Move {block} up', { block: name(node, i) })"
                            @click="move(i, -1, $event)"
                        />
                        <KButton
                            size="sm"
                            variant="ghost"
                            icon="arrow-down"
                            :disabled="i === list.length - 1 || locked(node, 'move')"
                            :aria-label="__('Move {block} down', { block: name(node, i) })"
                            @click="move(i, 1, $event)"
                        />
                        <KButton
                            v-if="defOf(node)"
                            size="sm"
                            variant="ghost"
                            icon="copy"
                            :aria-label="__('Duplicate {block}', { block: name(node, i) })"
                            @click="duplicate(i)"
                        />
                        <KButton
                            v-if="!locked(node, 'remove')"
                            size="sm"
                            variant="ghost"
                            icon="trash"
                            :aria-label="__('Remove {block}', { block: name(node, i) })"
                            @click="remove(i)"
                        />
                    </span>
                </div>

                <div v-if="defOf(node) && editor.isOpen(node.id)" class="k-blocks-ed__panel">
                    <KBlockSettings
                        :node="node"
                        :def="defOf(node)"
                        :bindings="editor.bindings.value"
                        :binding-label="editor.bindingLabel"
                        :disabled="disabled"
                        @update="update(i, $event)"
                    />
                </div>

                <div v-if="defOf(node)?.children" class="k-blocks-ed__children">
                    <KBlockList
                        :model-value="node.children ?? []"
                        :parent="defOf(node)"
                        :disabled="disabled"
                        :trail="`${trail ? `${trail} › ` : ''}${name(node, i)}`"
                        @update:model-value="update(i, { children: $event })"
                    />
                </div>
            </li>
        </ol>
        <p v-else-if="!parent" class="k-blocks-ed__empty">{{ __('No blocks yet. Add a heading, text, an image or a list of entries to start the page.') }}</p>
        <div v-if="!disabled" class="k-blocks-ed__add">
            <KButton size="sm" :variant="parent ? 'ghost' : 'secondary'" icon="plus" @click="insert(list.length)">{{ addLabel }}</KButton>
        </div>
    </div>
</template>

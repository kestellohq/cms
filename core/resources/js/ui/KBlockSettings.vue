<script setup>
import { computed, ref } from 'vue';
import { __ } from '@kestello/sdk';
import KContentField from './KContentField.vue';
import KIcon from './KIcon.vue';

/**
 * The settings of one block: its fields (typed or bound to data), who sees
 * it and on which screens, and (for people who design) what editors may
 * not change. Emits patches: { props } | { visibility } | { lock }.
 */
const props = defineProps({
    node: { type: Object, required: true },
    def: { type: Object, required: true },
    bindings: { type: Array, default: () => [] }, // [{ path, label }]
    bindingLabel: { type: Function, default: (p) => p },
    disabled: Boolean,
    lockable: Boolean, // show the lock settings (the builder)
    open: { type: String, default: '' }, // 'visibility' opens that section
    hideAbout: Boolean,
    inline: { type: Array, default: () => [] }, // fields typed on the page: long ones stay folded here
    // the builder shows the description itself
});
const emit = defineEmits(['update']);

const setProp = (key, value) => emit('update', { props: { ...(props.node.props ?? {}), [key]: value } });

// data bindings: a prop can show entry or site data instead of typed text
// typed text starts empty rather than missing, so a required one reads "required"
const TYPED_EMPTY = new Set(['text', 'textarea', 'rich_text', 'url', 'email']);
const bindable = (field) => (props.def.bindable ?? []).includes(field.name);
const bound = (field) => {
    const v = props.node.props?.[field.name];
    return v && typeof v === 'object' && typeof v.bind === 'string' ? v.bind : null;
};
const bindChoices = computed(() => props.bindings.map((b) => b.path));
const wildcard = (path) => (path ?? '').startsWith('entry.fields.');

// visibility
const vis = computed(() => props.node.visibility ?? {});
function setVisibility(key, value) {
    const v = { ...vis.value };
    if (value === '' || value === null || (Array.isArray(value) && !value.length)) delete v[key];
    else v[key] = value;
    emit('update', { visibility: Object.keys(v).length ? v : undefined });
}
const DEVICES = [
    ['phone', __('Phones'), 'device-mobile'],
    ['tablet', __('Tablets'), 'device-tablet'],
    ['desktop', __('Larger screens'), 'monitor'],
];
const hidden = (d) => (vis.value.hide ?? []).includes(d);
function toggleDevice(d) {
    const now = new Set(vis.value.hide ?? []);
    now.has(d) ? now.delete(d) : now.add(d);
    if (now.size === DEVICES.length) return; // hidden everywhere is removing it
    setVisibility('hide', [...now]);
}
const toLocal = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};
const fromLocal = (value) => (value ? new Date(value).toISOString() : '');

// locks
const LOCKS = [
    ['move', __('Moving it')],
    ['remove', __('Removing it')],
    ['edit', __('Changing its settings')],
];
const locked = (k) => (props.node.lock ?? []).includes(k);
function toggleLock(k) {
    const now = new Set(props.node.lock ?? []);
    now.has(k) ? now.delete(k) : now.add(k);
    emit('update', { lock: now.size ? [...now] : undefined });
}
const editLocked = computed(() => !props.lockable && locked('edit'));
const uid = computed(() => props.node.id ?? 'b');
// a rich text edited on the page needs no second, cramped editor here unless asked for
const unfolded = ref(new Set());
const folded = (field) => field.type === 'rich_text' && props.inline.includes(field.name) && bound(field) === null && !unfolded.value.has(field.name);
const unfold = (name) => {
    unfolded.value = new Set([...unfolded.value, name]);
};
</script>

<template>
    <div class="k-block-settings">
        <p v-if="def.description && !hideAbout" class="k-block-settings__about">{{ def.description }}</p>
        <p v-if="editLocked" class="k-block-settings__locked"><KIcon name="lock" />{{ __("This block's settings are locked by the layout.") }}</p>
        <div class="k-fields">
            <div
                v-for="field in def.fields"
                :key="field.name"
                class="k-block-settings__prop"
                :class="[`k-content-field--${field.width === 'half' ? 'half' : 'full'}`, { 'is-bindable': bindable(field) && !disabled && !editLocked }]"
            >
                <template v-if="bound(field) !== null">
                    <div class="k-field">
                        <label class="k-field__label" :for="`bind-${uid}-${field.name}`">{{ field.label }}</label>
                        <div class="k-block-settings__bind">
                            <select
                                :id="`bind-${uid}-${field.name}`"
                                class="k-input k-select"
                                :value="wildcard(bound(field)) ? 'entry.fields.*' : bound(field)"
                                :disabled="disabled || editLocked"
                                @change="setProp(field.name, { bind: $event.target.value === 'entry.fields.*' ? 'entry.fields.' : $event.target.value })"
                            >
                                <option v-for="b in bindChoices" :key="b" :value="b">{{ bindingLabel(b) }}</option>
                            </select>
                            <input
                                v-if="wildcard(bound(field))"
                                class="k-input k-block-settings__bind-name"
                                :value="bound(field).slice(13)"
                                :placeholder="__('field name')"
                                :aria-label="__('Field name')"
                                :disabled="disabled || editLocked"
                                spellcheck="false"
                                @input="setProp(field.name, { bind: `entry.fields.${$event.target.value.trim()}` })"
                            />
                        </div>
                    </div>
                </template>
                <div v-else-if="folded(field)" class="k-field">
                    <span class="k-field__label">{{ field.label }}</span>
                    <p class="k-block-settings__onpage">
                        <KIcon name="cursor-text" />{{ __('Edited on the page.') }}
                        <button type="button" class="k-block-settings__bind-toggle" @click="unfold(field.name)">{{ __('Edit here instead') }}</button>
                    </p>
                </div>
                <KContentField
                    v-else
                    :model-value="node.props?.[field.name] ?? null"
                    :field="field"
                    path="block"
                    :disabled="disabled || editLocked"
                    @update:model-value="setProp(field.name, $event)"
                />
                <!-- showing data: said in words under the choice; typed: a small button by the label offers it -->
                <button
                    v-if="bindable(field) && !disabled && !editLocked && bound(field) !== null"
                    type="button"
                    class="k-block-settings__bind-toggle"
                    @click="setProp(field.name, field.default ?? (TYPED_EMPTY.has(field.type) ? '' : null))"
                >
                    <KIcon name="pencil" />{{ __('Type it instead') }}
                </button>
                <button
                    v-else-if="bindable(field) && !disabled && !editLocked"
                    type="button"
                    class="k-block-settings__bind-icon"
                    :title="__('Show entry or site data instead')"
                    :aria-label="__('{field}: show entry or site data instead', { field: field.label })"
                    @click="setProp(field.name, { bind: 'entry.title' })"
                >
                    <KIcon name="database" />
                </button>
            </div>
        </div>

        <details class="k-block-settings__more" :open="open === 'visibility' || !!node.visibility">
            <summary>{{ __('Who sees this block') }}</summary>
            <div class="k-block-settings__group">
                <div class="k-field">
                    <label class="k-field__label" :for="`vis-${uid}`">{{ __('Visitors') }}</label>
                    <select
                        :id="`vis-${uid}`"
                        class="k-input k-select"
                        :value="vis.auth ?? ''"
                        :disabled="disabled"
                        @change="setVisibility('auth', $event.target.value)"
                    >
                        <option value="">{{ __('Everyone') }}</option>
                        <option value="guest">{{ __('Only visitors who are not signed in') }}</option>
                        <option value="user">{{ __('Only signed-in members') }}</option>
                    </select>
                </div>
                <fieldset class="k-block-settings__devices">
                    <legend class="k-field__label">{{ __('Screens') }}</legend>
                    <button
                        v-for="[d, label, icon] in DEVICES"
                        :key="d"
                        type="button"
                        class="k-block-settings__device"
                        :class="{ 'is-off': hidden(d) }"
                        :aria-pressed="String(!hidden(d))"
                        :disabled="disabled || (!hidden(d) && (vis.hide ?? []).length === DEVICES.length - 1)"
                        @click="toggleDevice(d)"
                    >
                        <KIcon :name="hidden(d) ? 'eye-slash' : icon" />{{ label }}
                    </button>
                </fieldset>
                <div class="k-field">
                    <label class="k-field__label" :for="`from-${uid}`">{{ __('Show from') }}</label>
                    <input
                        :id="`from-${uid}`"
                        type="datetime-local"
                        class="k-input"
                        :value="toLocal(vis.from)"
                        :disabled="disabled"
                        @change="setVisibility('from', fromLocal($event.target.value))"
                    />
                </div>
                <div class="k-field">
                    <label class="k-field__label" :for="`until-${uid}`">{{ __('Hide after') }}</label>
                    <input
                        :id="`until-${uid}`"
                        type="datetime-local"
                        class="k-input"
                        :value="toLocal(vis.until)"
                        :disabled="disabled"
                        @change="setVisibility('until', fromLocal($event.target.value))"
                    />
                </div>
            </div>
        </details>

        <details v-if="lockable" class="k-block-settings__more" :open="!!node.lock">
            <summary>{{ __("What editors can't change") }}</summary>
            <div class="k-block-settings__group">
                <p class="k-muted k-small">{{ __('For pages built from this layout. People who design can still change everything.') }}</p>
                <label v-for="[k, label] in LOCKS" :key="k" class="k-block-settings__lock">
                    <input type="checkbox" :checked="locked(k)" :disabled="disabled" @change="toggleLock(k)" /> {{ label }}
                </label>
            </div>
        </details>
    </div>
</template>

<style scoped>
.k-block-settings {
    display: grid;
    gap: 12px;
}
.k-block-settings__about {
    margin: 0;
    color: var(--k-ink-2);
    font-size: 13px;
}
.k-block-settings__locked {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    font-size: 13px;
    color: var(--k-ink-2);
}
.k-block-settings__bind {
    display: flex;
    gap: 6px;
}
.k-block-settings__bind select {
    flex: 1;
    min-width: 0;
}
.k-block-settings__bind-name {
    width: 9rem;
    font-family: var(--k-mono);
    font-size: 13px;
}
.k-block-settings__bind-toggle {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 4px;
    padding: 2px 0;
    border: 0;
    background: none;
    color: var(--k-ink-2);
    font: inherit;
    font-size: 12.5px;
    cursor: pointer;
}
.k-block-settings__bind-toggle:hover {
    color: var(--k-ink);
    text-decoration: underline;
}
.k-block-settings__prop {
    position: relative;
    min-width: 0;
}
/* the data button sits at the end of the label's line; the label keeps clear of it */
.k-block-settings__prop.is-bindable :deep(.k-field__label) {
    padding-right: 26px;
}
.k-block-settings__bind-icon {
    position: absolute;
    top: -3px;
    right: 0;
    display: grid;
    place-items: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 0;
    border-radius: var(--k-radius);
    background: none;
    color: var(--k-ink-3);
    font-size: 14px;
    cursor: pointer;
}
.k-block-settings__bind-icon:hover {
    background: var(--k-sheet-2);
    color: var(--k-ink);
}
.k-block-settings__bind-icon:focus-visible {
    outline: 2px solid var(--k-focus);
    outline-offset: 1px;
}
.k-block-settings__more {
    border-top: 1px solid var(--k-rule-soft);
    padding-top: 10px;
}
.k-block-settings__more summary {
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
}
.k-block-settings__group {
    display: grid;
    gap: 10px;
    margin-top: 10px;
}
.k-block-settings__devices {
    border: 0;
    margin: 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.k-block-settings__devices legend {
    width: 100%;
    padding: 0;
    margin-bottom: 4px;
}
.k-block-settings__device {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 9px;
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    background: var(--k-sheet);
    color: var(--k-ink);
    font: inherit;
    font-size: 13px;
    cursor: pointer;
}
.k-block-settings__device.is-off {
    color: var(--k-ink-3);
    background: var(--k-sheet-2);
    text-decoration: line-through;
}
.k-block-settings__device:focus-visible {
    outline: 2px solid var(--k-focus);
    outline-offset: 2px;
}
.k-block-settings__onpage {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 6px;
    margin: 0;
    font-size: 12.5px;
    color: var(--k-ink-2);
}
.k-block-settings__onpage .k-block-settings__bind-toggle {
    margin: 0;
}
.k-block-settings__lock {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}
</style>

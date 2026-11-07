<script setup>
import { computed, ref } from 'vue';
import { __ } from '@kestello/sdk';

/**
 * The changes of one revision, field by field, from
 * GET …/revisions/{id}/diff. Added text is underlined on green, removed
 * text struck through on red; runs of unchanged lines fold away.
 */
const props = defineProps({ changes: { type: Array, required: true } });
const CONTEXT = 2; // unchanged lines kept around a change
const open = ref({}); // "field:index" of folds the reader opened

/** Lines with long unchanged runs replaced by a fold. */
function rows(change) {
    const lines = change.lines;
    const keep = lines.map((l, i) => l.op !== 'equal' || lines.slice(Math.max(0, i - CONTEXT), i + CONTEXT + 1).some((n) => n.op !== 'equal'));
    const out = [];
    for (let i = 0; i < lines.length; i++) {
        if (keep[i] || open.value[`${change.key}:${i}`]) {
            out.push({ ...lines[i], index: i });
            continue;
        }
        let j = i;
        while (j < lines.length && !keep[j]) j++;
        out.push({ op: 'fold', index: i, count: j - i });
        i = j - 1;
    }
    return out;
}
function unfold(change, row) {
    for (let i = row.index; i < row.index + row.count; i++) open.value[`${change.key}:${i}`] = true;
}
const list = computed(() => props.changes.map((c) => ({ ...c, rows: rows(c) })));
// (an ICU plural's closing braces would end a {{ }} in the template)
const unchangedText = (n) => __('{n, plural, one {# unchanged line} other {# unchanged lines}}', { n });
</script>

<template>
    <p v-if="!changes.length" class="k-muted">{{ __('No changes to the content.') }}</p>
    <section v-for="change in list" :key="change.key" class="k-diff">
        <h3 class="k-diff__label">{{ change.label }}</h3>
        <div class="k-diff__lines">
            <template v-for="row in change.rows" :key="row.index">
                <button v-if="row.op === 'fold'" type="button" class="k-diff__fold" @click="unfold(change, row)">
                    {{ unchangedText(row.count) }}
                </button>
                <p v-else-if="row.op === 'equal'" class="k-diff__line">{{ row.text || '\u00a0' }}</p>
                <p v-else-if="row.op === 'insert'" class="k-diff__line is-insert">
                    <span class="k-sr-only">{{ __('Added:') }} </span><ins>{{ row.text || '\u00a0' }}</ins>
                </p>
                <p v-else-if="row.op === 'delete'" class="k-diff__line is-delete">
                    <span class="k-sr-only">{{ __('Removed:') }} </span><del>{{ row.text || '\u00a0' }}</del>
                </p>
                <p v-else class="k-diff__line is-change">
                    <template v-for="(part, i) in row.parts" :key="i">
                        <ins v-if="part[0] === 'insert'"
                            ><span class="k-sr-only">{{ __('Added:') }} </span>{{ part[1] }}</ins
                        >
                        <del v-else-if="part[0] === 'delete'"
                            ><span class="k-sr-only">{{ __('Removed:') }} </span>{{ part[1] }}</del
                        >
                        <template v-else>{{ part[1] }}</template>
                    </template>
                </p>
            </template>
        </div>
    </section>
</template>

<style scoped>
.k-diff + .k-diff {
    margin-top: 20px;
}
.k-diff__label {
    margin: 0 0 6px;
    font-size: 13px;
    font-weight: 600;
    color: var(--k-ink-2);
}
.k-diff__lines {
    border: 1px solid var(--k-rule);
    border-radius: var(--k-radius);
    overflow: hidden;
}
.k-diff__line {
    margin: 0;
    padding: 4px 10px;
    font-size: 14px;
    line-height: 1.55;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.k-diff__line + .k-diff__line,
.k-diff__line + .k-diff__fold,
.k-diff__fold + .k-diff__line {
    border-top: 1px solid var(--k-rule-soft);
}
.k-diff__line.is-insert {
    background: var(--k-success-soft);
}
.k-diff__line.is-delete {
    background: var(--k-danger-soft);
}
ins {
    text-decoration: underline;
    text-decoration-color: color-mix(in srgb, var(--k-success) 70%, transparent);
    text-underline-offset: 3px;
    background: color-mix(in srgb, var(--k-success) 18%, transparent);
    color: inherit;
}
del {
    text-decoration: line-through;
    text-decoration-color: color-mix(in srgb, var(--k-danger) 70%, transparent);
    background: color-mix(in srgb, var(--k-danger) 14%, transparent);
    color: inherit;
}
.k-diff__line.is-insert ins,
.k-diff__line.is-delete del {
    background: none;
}
.k-diff__fold {
    display: block;
    width: 100%;
    padding: 3px 10px;
    border: 0;
    background: var(--k-sheet-2);
    color: var(--k-ink-3);
    font: inherit;
    font-size: 12px;
    text-align: left;
    cursor: pointer;
}
.k-diff__fold:hover,
.k-diff__fold:focus-visible {
    text-decoration: underline;
}
</style>

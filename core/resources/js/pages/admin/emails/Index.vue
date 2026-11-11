<script setup>
import { computed } from 'vue';
import { Head, Link } from '@kestello/app';
import { useResource, __ } from '@kestello/sdk';
import { KAlert, KBadge, KButton, KCard, KPageHeader, KTable } from '@kestello/ui';

defineOptions({ layout: 'Admin' });

/** Site → Emails: every email the site sends, by group, and the languages each one is edited in. */
const list = useResource('/emails');
const languages = computed(() => list.data.value?.languages ?? []);
const multilingual = computed(() => languages.value.length > 1);
const nameOf = (code) => languages.value.find((l) => l.code === code)?.name ?? code;

// one table per group, in the order the emails were registered
const groups = computed(() => {
    const out = new Map();
    for (const email of list.data.value?.emails ?? []) {
        if (!out.has(email.group)) out.set(email.group, []);
        out.get(email.group).push(email);
    }
    return [...out.entries()].map(([title, rows]) => ({ title, rows }));
});

const columns = [
    { key: 'label', label: __('Email') },
    { key: 'edited', label: __('Wording'), width: '220px' },
    { key: 'actions', label: '', align: 'end', width: '96px' },
];
</script>

<template>
    <Head :title="__('Emails')" />
    <KPageHeader
        :title="__('Emails')"
        :description="
            __(
                'Every email the site sends. Change the words, add images and buttons, and check each one before it reaches anyone. The colours, logo and footer are under Settings → Emails.',
            )
        "
    >
        <template #actions>
            <KButton icon="gear" href="/admin/settings?tab=emails">{{ __('Logo and footer') }}</KButton>
        </template>
    </KPageHeader>

    <KAlert v-if="list.error.value" tone="danger">{{ list.error.value.message }}</KAlert>

    <KCard v-for="group in groups" :key="group.title" :title="group.title" flush class="k-emails__group">
        <KTable :columns="columns" :rows="group.rows" row-key="id">
            <template #cell-label="{ row }">
                <Link :href="`/admin/emails/${row.id}`" class="k-link k-emails__title">{{ row.label }}</Link>
                <div class="k-muted k-small">{{ row.description }}</div>
            </template>
            <template #cell-edited="{ row }">
                <span v-if="!row.edited.length" class="k-muted k-small">{{ __('As written') }}</span>
                <span v-else-if="!multilingual"
                    ><KBadge tone="info">{{ __('Edited') }}</KBadge></span
                >
                <span v-else class="k-emails__langs">
                    <KBadge v-for="code in row.edited" :key="code" tone="info">{{ __('Edited in {language}', { language: nameOf(code) }) }}</KBadge>
                </span>
            </template>
            <template #cell-actions="{ row }">
                <KButton size="sm" variant="ghost" icon="pencil" :href="`/admin/emails/${row.id}`">{{ __('Edit') }}</KButton>
            </template>
        </KTable>
    </KCard>
    <KCard v-if="list.loading.value && !list.data.value" flush><KTable :columns="columns" :rows="[]" loading /></KCard>
</template>

<style scoped>
.k-emails__group + .k-emails__group {
    margin-top: 16px;
}
.k-emails__title {
    font-weight: 600;
}
.k-emails__langs {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 4px;
}
</style>

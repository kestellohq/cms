<script setup>
defineProps({ items: { type: Object, required: true } }); // { label: value }
</script>

<template>
    <dl class="k-kv">
        <template v-for="(value, key) in items" :key="key">
            <dt>{{ key }}</dt>
            <dd>{{ value === true ? 'Yes' : value === false ? 'No' : (value ?? '—') }}</dd>
        </template>
    </dl>
</template>

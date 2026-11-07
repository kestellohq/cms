<script setup>
defineProps({ ordered: Boolean, items: { type: Array, default: () => [] } });
</script>

<template>
    <div v-if="items.length" class="k-b-list">
        <component :is="ordered ? 'ol' : 'ul'">
            <li v-for="(item, i) in items" :key="i">{{ item?.text }}</li>
        </component>
    </div>
</template>

<style scoped>
.k-b-list > ul,
.k-b-list > ol {
    margin: 0;
    padding-left: 1.3em;
    font-size: 1.075rem;
    line-height: 1.6;
}
.k-b-list li + li {
    margin-top: 0.4em;
}
</style>

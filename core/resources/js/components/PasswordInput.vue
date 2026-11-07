<script setup>
// A password field with a button that shows what was typed.
import { ref } from 'vue';
import { __ } from '@kestello/sdk';
import { KIcon } from '@kestello/ui';

defineOptions({ inheritAttrs: false }); // id, autocomplete, required… belong on the input
const model = defineModel({ type: String, default: '' });
defineProps({ invalid: Boolean });
const shown = ref(false);
</script>

<template>
    <div class="k-password">
        <input
            v-bind="$attrs"
            v-model="model"
            :type="shown ? 'text' : 'password'"
            class="k-input"
            :aria-invalid="invalid ? 'true' : undefined"
            spellcheck="false"
            autocapitalize="off"
        />
        <button
            type="button"
            class="k-password__toggle"
            :aria-pressed="shown ? 'true' : 'false'"
            :aria-label="shown ? __('Hide password') : __('Show password')"
            @click="shown = !shown"
        >
            <KIcon :name="shown ? 'eye-slash' : 'eye'" />
        </button>
    </div>
</template>

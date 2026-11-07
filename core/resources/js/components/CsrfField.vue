<script setup>
// Hidden CSRF input for plain (non-router) form posts.
import { usePage } from '@kestello/app';

const page = usePage();
</script>

<template>
    <input type="hidden" name="_csrf_token" :value="page.props.csrfToken" />
</template>

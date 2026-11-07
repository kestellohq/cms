// ESLint: correctness rules for Kestello's JavaScript and Vue. Formatting is Prettier's job
// (.prettierrc.json), so no style rules here. Run: npm run lint:js
import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import globals from 'globals';

export default [
    {
        ignores: ['**/node_modules/**', '**/assets/build/**', '**/assets/vendor/**', 'storage/**', 'tools/bin/**', 'dist/**', 'core/releases/**'],
    },
    {
        // as in .gitignore: content/ holds the local site's own plugins and themes, apart from these
        ignores: ['content/plugins/**', 'content/themes/*', '!content/themes/base'],
    },
    js.configs.recommended,
    ...pluginVue.configs['flat/essential'],
    {
        // the admin, the site's themes and plugins run in the browser
        files: ['core/resources/js/**', 'content/**'],
        languageOptions: { globals: { ...globals.browser } },
    },
    {
        // build, server-side rendering, tests and tools run in Node
        files: ['core/build/**', 'core/ssr/**', 'tests/js/**', 'tools/**', 'eslint.config.js'],
        languageOptions: { globals: { ...globals.node } },
    },
    {
        rules: {
            'no-unused-vars': ['error', { args: 'none', caughtErrors: 'none', varsIgnorePattern: '^_', ignoreRestSiblings: true }],
            'no-empty': ['error', { allowEmptyCatch: true }],
            // pages are routed by file name (pages/admin/content/Edit.vue), so one-word names are the convention
            'vue/multi-word-component-names': 'off',
        },
    },
    {
        // the app's own <Link> and <Head> components, named like the Inertia ones they replace
        files: ['core/resources/js/app/index.js'],
        rules: { 'vue/no-reserved-component-names': 'off' },
    },
    {
        // these editors change the field definitions they are handed in place: the parent owns
        // one reactive draft of the whole content type and saves it as a unit
        files: ['core/resources/js/components/content-types/ConditionsEditor.vue', 'core/resources/js/components/content-types/FieldList.vue'],
        rules: { 'vue/no-mutating-props': 'off' },
    },
];

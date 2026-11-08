/**
 * The Vue the prerender frame imports as both `vue` and `vue/server-renderer`:
 * Vue's runtime and its server renderer in one module, so they share one copy
 * of Vue (the browser build of the renderer carries its own). Built by
 * core/build/prerender.mjs into assets/build/prerender/vue.js.
 */
export * from 'vue';
export * from '@vue/server-renderer';

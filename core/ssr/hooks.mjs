// Module resolution for SSR bundles: built pages import the bare specifiers
// "vue", "@kestello/app", "@kestello/sdk" and "@kestello/ui" (externals). Map
// them to one shared instance each, so every page, layout and the runtime use
// the same Vue, injection keys and translations.
let shared = {};

export async function initialize(data) {
    shared = { '@kestello/app': data.runtime, '@kestello/sdk': data.sdk, '@kestello/ui': data.ui, vue: data.vue };
}

export async function resolve(specifier, context, next) {
    if (Object.hasOwn(shared, specifier) && shared[specifier]) {
        return { url: shared[specifier], shortCircuit: true };
    }
    return next(specifier, context);
}

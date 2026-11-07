/**
 * The visual editor is one shared state (KBuilder creates it) and the parts that
 * show it: the bar, layers, library, toolbar, quick inserter and inspector. Each
 * part reads the state with useBuilder(); nothing is passed down as props.
 *
 * The state is put together from composables, each adding its own fields to the
 * same object, so a part can call into another (selecting a block also tells the
 * canvas). Order of creation: catalog, document, canvas, drag, quick insert, panels.
 */
import { inject, provide } from 'vue';

const BUILDER = Symbol('kestello.builder');

export function provideBuilder(state) {
    provide(BUILDER, state);
    return state;
}

export function useBuilder() {
    const state = inject(BUILDER, null);
    if (!state) throw new Error('useBuilder() is for the parts of KBuilder');
    return state;
}

/**
 * The visual editor's state, put together (see context.js for how the parts use it).
 */
import { useApi } from '@kestello/sdk';
import { provideBuilder } from './context.js';
import { useCanvas } from './useCanvas.js';
import { useCatalog } from './useCatalog.js';
import { useDocument } from './useDocument.js';
import { useDrag } from './useDrag.js';
import { useKeyboard } from './useKeyboard.js';
import { useFind } from './useFind.js';
import { useLibrary } from './useLibrary.js';
import { useMulti } from './useMulti.js';
import { usePanels } from './usePanels.js';
import { usePatterns } from './usePatterns.js';
import { useQuickInsert } from './useQuickInsert.js';

/**
 * @param {{ tree: import('vue').Ref<object[]>, props: object, emit: Function, root: import('vue').Ref<HTMLElement|null> }} env
 */
export function useBuilderState(env) {
    const b = { props: env.props };
    const deps = { ...env, api: useApi() };
    Object.assign(b, useCatalog(deps));
    Object.assign(b, useDocument(b, deps));
    Object.assign(b, useMulti(b, deps));
    Object.assign(b, useLibrary(b, deps));
    Object.assign(b, usePatterns(b, deps));
    Object.assign(b, useFind(b, deps));
    Object.assign(b, usePanels(b, deps));
    Object.assign(b, useCanvas(b, deps));
    Object.assign(b, useDrag(b, deps));
    Object.assign(b, useQuickInsert(b, deps));
    Object.assign(b, useKeyboard(b, deps));
    return provideBuilder(b);
}

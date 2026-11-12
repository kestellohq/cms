// A <script setup> runs top to bottom. A computed waits until something reads it, but watch() reads
// its source at once (and runs its callback at once with immediate: true, as watchEffect does). If
// that reaches, through computeds and helper functions, a const declared further down, the page
// throws "Cannot access 'x' before initialization" as it opens. This walks every component's
// watchers and fails on any such path, naming it.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parse as parseSfc } from '@vue/compiler-sfc';
import { parse } from '@babel/parser';

const root = fileURLToPath(new URL('../../', import.meta.url));
const folders = ['core/resources/js', 'content/themes'];

function vueFiles(dir) {
    let entries;
    try {
        entries = readdirSync(dir, { withFileTypes: true });
    } catch {
        return [];
    }
    return entries.flatMap((e) => {
        const path = join(dir, e.name);
        if (e.isDirectory()) return e.name === 'node_modules' || e.name === 'build' ? [] : vueFiles(path);
        return e.name.endsWith('.vue') ? [path] : [];
    });
}

const isFunction = (node) => node?.type === 'ArrowFunctionExpression' || node?.type === 'FunctionExpression';

/** The names a piece of code reads that are not its own parameters or locals. */
export function reads(node) {
    const found = new Set();
    const own = new Set();
    (function walk(n, parent, key) {
        if (!n || typeof n.type !== 'string') return;
        if (n.type === 'Identifier') {
            const isProperty = (parent?.type === 'MemberExpression' || parent?.type === 'OptionalMemberExpression') && key === 'property' && !parent.computed;
            const isKey = (parent?.type === 'ObjectProperty' || parent?.type === 'ObjectMethod') && key === 'key' && !parent.computed;
            if (!isProperty && !isKey) found.add(n.name);
            return;
        }
        if (isFunction(n) || n.type === 'FunctionDeclaration') {
            for (const p of n.params) collectNames(p, own);
        }
        if (n.type === 'VariableDeclarator') collectNames(n.id, own);
        if (n.type === 'CatchClause' && n.param) collectNames(n.param, own);
        for (const [k, v] of Object.entries(n)) {
            if (k === 'loc' || k === 'start' || k === 'end' || k === 'leadingComments' || k === 'trailingComments') continue;
            if (Array.isArray(v)) v.forEach((c) => walk(c, n, k));
            else if (v && typeof v === 'object') walk(v, n, k);
        }
    })(node, null, null);
    for (const name of own) found.delete(name);
    return found;
}

function collectNames(pattern, into) {
    if (!pattern) return;
    if (pattern.type === 'Identifier') into.add(pattern.name);
    else if (pattern.type === 'ObjectPattern') pattern.properties.forEach((p) => collectNames(p.type === 'RestElement' ? p.argument : p.value, into));
    else if (pattern.type === 'ArrayPattern') pattern.elements.forEach((e) => collectNames(e, into));
    else if (pattern.type === 'AssignmentPattern') collectNames(pattern.left, into);
    else if (pattern.type === 'RestElement') collectNames(pattern.argument, into);
}

/**
 * Paths from a watcher to a binding declared after it, in one <script setup>.
 *
 * @returns {string[]} "line 91: watch reads studio → visualField → fields → entry (declared on line 147)"
 */
export function readsBeforeDeclared(code, lineOffset = 0) {
    const body = parse(code, { sourceType: 'module', plugins: ['jsx'] }).program.body;
    const declared = new Map(); // name → { index, line, lazy: names it reads when it runs, or null for a value }
    body.forEach((stmt, index) => {
        if (stmt.type === 'FunctionDeclaration' && stmt.id) {
            // hoisted: callable from anywhere, but what it reads still has to exist when it runs
            declared.set(stmt.id.name, { index: -1, line: stmt.loc.start.line, lazy: reads(stmt.body) });
        }
        if (stmt.type !== 'VariableDeclaration') return;
        for (const d of stmt.declarations) {
            const init = d.init;
            const isComputed = init?.type === 'CallExpression' && init.callee.name === 'computed' && isFunction(init.arguments[0]);
            const lazy = isComputed ? reads(init.arguments[0]) : isFunction(init) ? reads(init) : null;
            const names = new Set();
            collectNames(d.id, names);
            for (const name of names) declared.set(name, { index, line: stmt.loc.start.line, lazy });
        }
    });

    const problems = [];
    body.forEach((stmt, index) => {
        const call = stmt.type === 'ExpressionStatement' ? stmt.expression : null;
        if (call?.type !== 'CallExpression' || !['watch', 'watchEffect'].includes(call.callee.name)) return;
        const [source, callback, options] = call.arguments;
        const immediate = options?.type === 'ObjectExpression' && options.properties.some((p) => p.key?.name === 'immediate' && p.value.value === true);
        const now = new Set(reads(source));
        if (call.callee.name === 'watch' && immediate && callback) reads(callback).forEach((n) => now.add(n));

        // breadth first, so the path reported is the shortest
        const via = new Map([...now].map((n) => [n, [n]]));
        const queue = [...now];
        while (queue.length) {
            const name = queue.shift();
            const binding = declared.get(name);
            if (!binding) continue;
            if (binding.index > index) {
                problems.push(
                    `line ${call.loc.start.line + lineOffset}: ${call.callee.name} reads ${via.get(name).join(' → ')} (declared on line ${binding.line + lineOffset})`,
                );
                continue;
            }
            for (const next of binding.lazy ?? []) {
                if (!via.has(next)) {
                    via.set(next, [...via.get(name), next]);
                    queue.push(next);
                }
            }
        }
    });
    return problems;
}

test('a watcher reading a const declared below it is found, with the path', () => {
    const code = [
        "import { computed, ref, watch } from 'vue';",
        'const fields = computed(() => (entry.value?.translations ?? []).length);',
        'const studio = computed(() => fields.value > 0);',
        'watch(studio, () => {});',
        'const entry = ref(null);',
    ].join('\n');
    assert.deepEqual(readsBeforeDeclared(code), ['line 4: watch reads studio → fields → entry (declared on line 5)']);
});

test('reading it later, or only from a callback that waits, is fine', () => {
    const code = [
        "import { computed, ref, watch } from 'vue';",
        'const total = computed(() => later.value + 1);',
        'watch(() => early.value, () => later.value);',
        'const early = ref(0);',
        'const later = ref(0);',
        'watch(total, () => {});',
    ].join('\n');
    // the first watch reads early, declared below it: still a problem; its callback is not run yet
    assert.deepEqual(readsBeforeDeclared(code), ['line 3: watch reads early (declared on line 4)']);
    const fine = ["import { computed, ref, watch } from 'vue';", 'const a = ref(0);', 'watch(a, () => b.value);', 'const b = ref(0);'].join('\n');
    assert.deepEqual(readsBeforeDeclared(fine), []);
});

test('immediate watchers and watchEffect run their callback at once', () => {
    const code = [
        "import { ref, watch, watchEffect } from 'vue';",
        'const a = ref(0);',
        'watch(a, () => b.value, { immediate: true });',
        'watchEffect(() => load());',
        'function load() { return c.value; }',
        'const b = ref(0);',
        'const c = ref(0);',
    ].join('\n');
    assert.deepEqual(readsBeforeDeclared(code), ['line 3: watch reads b (declared on line 6)', 'line 4: watchEffect reads load → c (declared on line 7)']);
});

test('no component reads a value before it is declared as it opens', () => {
    const problems = [];
    const files = folders.flatMap((folder) => vueFiles(join(root, folder)));
    assert.ok(files.length > 100, `found only ${files.length} components`);
    {
        for (const file of files) {
            const { descriptor } = parseSfc(readFileSync(file, 'utf8'), { filename: file });
            const setup = descriptor.scriptSetup;
            if (!setup) continue;
            for (const p of readsBeforeDeclared(setup.content, setup.loc.start.line - 1)) problems.push(`${relative(root, file)} ${p}`);
        }
    }
    assert.deepEqual(problems, []);
});

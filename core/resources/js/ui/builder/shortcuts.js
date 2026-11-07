/**
 * Keyboard shortcuts: how they are written for this computer, and the list the
 * shortcuts dialog shows. Combos are written as "Mod+Shift+D" (Mod: ⌘ on a Mac,
 * Ctrl elsewhere).
 */
export const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform ?? '');

export function keyLabel(combo, mac = isMac) {
    const keys = combo
        .replace(/\bMod\b/g, mac ? '⌘' : 'Ctrl')
        .replace(/\bAlt\b/g, mac ? '⌥' : 'Alt')
        .replace(/\bShift\b/g, mac ? '⇧' : 'Shift');
    return mac ? keys.replace(/([⌘⌥⇧])\+/g, '$1') : keys;
}

/** Groups of [what, combo] for the shortcuts dialog. */
export const shortcutGroups = (t) => [
    [
        t('Editing'),
        [
            [t('Add a block'), '/'],
            [t('Undo'), 'Mod+Z'],
            [t('Redo'), 'Mod+Shift+Z'],
            [t('Save'), 'Mod+S'],
            [t('Duplicate the block'), 'Mod+Shift+D'],
            [t('Remove the block'), 'Alt+Shift+Z'],
            [t('Add a block before'), 'Mod+Alt+T'],
            [t('Add a block after'), 'Mod+Alt+Y'],
            [t("Copy the block's styles"), 'Mod+Alt+C'],
            [t('Paste styles onto the block'), 'Mod+Alt+V'],
        ],
    ],
    [
        t('Text'),
        [
            [t('Bold'), 'Mod+B'],
            [t('Italic'), 'Mod+I'],
            [t('Link'), 'Mod+K'],
            [t('Strikethrough'), 'Alt+Shift+D'],
            [t('Inline code'), 'Alt+Shift+X'],
            [t('New block (on an empty line)'), 'Enter'],
            [t('Line break'), 'Shift+Enter'],
        ],
    ],
    [
        t('Moving around'),
        [
            [t('Layers'), 'Alt+Shift+O'],
            [t('Find and replace'), 'Mod+Shift+F'],
            [t('Settings panel'), 'Mod+Shift+,'],
            [t('Hide both panels'), 'Mod+Shift+\\'],
            [t('Move the block (in layers)'), 'Alt+↑↓'],
            [t('Select the parent (in layers)'), '←'],
            [t('These shortcuts'), '?'],
        ],
    ],
];

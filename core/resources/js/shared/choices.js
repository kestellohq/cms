/**
 * What a visitor sees for a choice: its label, or its value when it has
 * none (as the server stores it: Forms::sanitize falls back the same way).
 * @param {{ value: string, label?: string }} choice
 */
export const choiceLabel = (choice) => (String(choice.label ?? '').trim() !== '' ? choice.label : choice.value);

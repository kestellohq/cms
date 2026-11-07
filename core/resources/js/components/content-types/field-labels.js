/** What each field type is called in the editors. */
import { __ } from '@kestello/sdk';

export function fieldTypeLabel(type) {
    return (
        {
            text: __('Text'),
            textarea: __('Long text'),
            rich_text: __('Formatted text'),
            number: __('Number'),
            boolean: __('Yes or no'),
            date: __('Date'),
            datetime: __('Date and time'),
            email: __('Email address'),
            url: __('Web address'),
            link: __('Link'),
            color: __('Color'),
            select: __('Choice'),
            multi_select: __('Several choices'),
            media: __('Media'),
            entry: __('Linked entry'),
            term: __('Term'),
            user: __('Person'),
            blocks: __('Blocks'),
            group: __('Group'),
            repeater: __('Repeating group'),
            file: __('File upload'),
        }[type] ?? type
    );
}

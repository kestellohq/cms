/**
 * What a package or release signature means, for the review dialogs on the Updates screen.
 * `s` is PackageSignature::verify(): { status, key, name }.
 */
import { __ } from '@kestello/sdk';

/** @returns {{ icon: string, tone: 'success'|'warning'|'danger', text: string }} */
export function signatureNote(s) {
    return {
        signed: { icon: 'seal-check', tone: 'success', text: __('Signed by {name}, a publisher this site trusts.', { name: s.name }) },
        untrusted: { icon: 'seal-question', tone: 'warning', text: __('Signed with a key this site does not trust ({key}).', { key: s.key }) },
        unsigned: { icon: 'seal-question', tone: 'warning', text: __('Not signed: nobody vouches for where it came from.') },
        tampered: { icon: 'seal-warning', tone: 'danger', text: __('Changed after it was signed. Do not apply it.') },
    }[s.status];
}

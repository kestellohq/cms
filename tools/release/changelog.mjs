/**
 * The release scripts' logic, pure so it can be tested (tests/js/release.test.mjs):
 * versions, the changelog's Unreleased section, and release notes.
 * CHANGELOG.md follows Keep a Changelog 1.1.
 */

/** SemVer 2.0 without build metadata: 1.2.3, 1.2.3-beta.1, 1.2.3-rc.2. */
const SEMVER = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?$/;

export const REPOSITORY = 'https://github.com/kestellohq/cms';

/** @returns {{ major: number, minor: number, patch: number, pre: string|null }} */
export function parseVersion(version) {
    const m = SEMVER.exec(String(version ?? '').trim());
    if (!m) throw new Error(`"${version}" is not a version like 1.2.3 or 1.2.3-beta.1`);
    return { major: Number(m[1]), minor: Number(m[2]), patch: Number(m[3]), pre: m[4] ?? null };
}

export const isPrerelease = (version) => parseVersion(version).pre !== null;

/** Negative, zero or positive, as SemVer orders versions (a pre-release comes before its release). */
export function compareVersions(a, b) {
    const x = parseVersion(a);
    const y = parseVersion(b);
    for (const k of ['major', 'minor', 'patch']) if (x[k] !== y[k]) return x[k] - y[k];
    if (x.pre === y.pre) return 0;
    if (x.pre === null) return 1;
    if (y.pre === null) return -1;
    const p = x.pre.split('.');
    const q = y.pre.split('.');
    for (let i = 0; i < Math.max(p.length, q.length); i++) {
        if (p[i] === undefined) return -1;
        if (q[i] === undefined) return 1;
        const [n, m] = [/^\d+$/.test(p[i]), /^\d+$/.test(q[i])];
        if (n && m && Number(p[i]) !== Number(q[i])) return Number(p[i]) - Number(q[i]);
        if (n !== m) return n ? -1 : 1;
        if (!n && p[i] !== q[i]) return p[i] < q[i] ? -1 : 1;
    }
    return 0;
}

const HEADING = /^## \[([^\]]+)\](.*)$/;

/** The changelog's version sections, in order: { name, line, end } (end is exclusive). */
function sections(lines) {
    const out = [];
    lines.forEach((line, i) => {
        const m = HEADING.exec(line);
        if (m) out.push({ name: m[1], line: i, end: lines.length });
    });
    for (let i = 0; i < out.length - 1; i++) out[i].end = out[i + 1].line;
    // link references ("[1.0.0]: https://...") close the last section
    if (out.length) {
        const last = out[out.length - 1];
        const ref = lines.findIndex((l, i) => i > last.line && /^\[[^\]]+\]: /.test(l));
        if (ref >= 0) last.end = ref;
    }
    return out;
}

const hasEntries = (lines) => lines.some((l) => /^\s*[-*] \S/.test(l));

/**
 * The changelog with Unreleased released as `version` on `date` (YYYY-MM-DD): its entries
 * move under "## [version] - date", a fresh empty Unreleased section stays on top, and the
 * comparison links at the bottom are updated.
 *
 * Pre-releases get no section: their notes are Unreleased as it stands (releaseNotes), and the
 * release they lead to takes every change at once, so 0.1.0 lists all of 0.1.0, not only what
 * changed after its last release candidate.
 */
export function releaseChangelog(text, version, date) {
    if (isPrerelease(version)) throw new Error(`${version} is a pre-release: it leaves CHANGELOG.md as it is (its notes are the Unreleased section)`);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) throw new Error(`"${date}" is not a date like 2026-11-06`);
    const lines = text.replace(/\r\n/g, '\n').split('\n');
    const all = sections(lines);
    const unreleased = all.find((s) => s.name === 'Unreleased');
    if (!unreleased) throw new Error('CHANGELOG.md has no "## [Unreleased]" section');
    if (all.some((s) => s.name === version)) throw new Error(`CHANGELOG.md already has a section for ${version}`);
    const body = lines.slice(unreleased.line + 1, unreleased.end);
    if (!hasEntries(body)) throw new Error('There is nothing under "## [Unreleased]" to release: add the changes first');

    const previous = all.find((s) => s.name !== 'Unreleased')?.name ?? null;
    const trimmed = trimBlank(body);
    const out = [...lines.slice(0, unreleased.line), '## [Unreleased]', '', `## [${version}] - ${date}`, '', ...trimmed, '', ...lines.slice(unreleased.end)];

    // comparison links: the new ones on top of the old, everything else left where it was
    const ours = new Set(['Unreleased', ...all.map((s) => s.name)]);
    const isOurRef = (l) => /^\[[^\]]+\]: https?:\/\//.test(l) && ours.has(/^\[([^\]]+)\]:/.exec(l)[1]);
    const kept = out.filter((l) => !isOurRef(l));
    const oldRefs = out.filter((l) => isOurRef(l) && !l.startsWith('[Unreleased]:'));
    const links = [
        `[Unreleased]: ${REPOSITORY}/compare/v${version}...develop`,
        previous ? `[${version}]: ${REPOSITORY}/compare/v${previous}...v${version}` : `[${version}]: ${REPOSITORY}/releases/tag/v${version}`,
        ...oldRefs,
    ];
    return `${trimBlank(kept).join('\n')}\n\n${links.join('\n')}\n`;
}

function trimBlank(lines) {
    let a = 0;
    let b = lines.length;
    while (a < b && lines[a].trim() === '') a++;
    while (b > a && lines[b - 1].trim() === '') b--;
    return lines.slice(a, b);
}

/**
 * One version's section, without its heading: the GitHub release notes. A pre-release has no
 * section: its notes are the Unreleased section, under a line that says what it is.
 */
export function releaseNotes(text, version) {
    const lines = text.replace(/\r\n/g, '\n').split('\n');
    if (isPrerelease(version)) {
        const unreleased = sections(lines).find((s) => s.name === 'Unreleased');
        const body = unreleased ? trimBlank(lines.slice(unreleased.line + 1, unreleased.end)) : [];
        if (!hasEntries(body)) throw new Error(`The Unreleased section of CHANGELOG.md is empty, so ${version} has no notes`);
        const { major, minor, patch } = parseVersion(version);
        const release = `${major}.${minor}.${patch}`;
        return `A pre-release of Kestello ${release}, for testing. Sites are offered it only with \`release.prereleases\` on, or when they already run a pre-release of ${release}.\n\n${body.join('\n')}\n`;
    }
    const section = sections(lines).find((s) => s.name === version);
    if (!section) throw new Error(`CHANGELOG.md has no section for ${version}`);
    const body = trimBlank(lines.slice(section.line + 1, section.end));
    if (!hasEntries(body)) throw new Error(`The ${version} section of CHANGELOG.md is empty`);
    return `${body.join('\n')}\n`;
}

/** Does the Unreleased section list anything? (the pull request check) */
export function unreleasedHasEntries(text) {
    const lines = text.replace(/\r\n/g, '\n').split('\n');
    const section = sections(lines).find((s) => s.name === 'Unreleased');
    return section ? hasEntries(lines.slice(section.line + 1, section.end)) : false;
}

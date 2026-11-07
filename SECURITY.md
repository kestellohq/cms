# Security policy

Kestello runs sites for people who will never read its code, so we treat security reports as the most important thing we receive. Thank you for taking the time to report one responsibly.

## Reporting a vulnerability

**Please don't open a public issue, discussion or pull request.**

Report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability** ([direct link](https://github.com/kestellohq/cms/security/advisories/new)). Only the maintainers see it.

A useful report has:

- the Kestello version (Settings, or `core/VERSION`), PHP version and database,
- what an attacker can do, and what they need first (an account, a capability, a setting),
- the steps or a proof of concept, kept to the minimum that shows the problem,
- any idea you have for the fix.

## What happens next

| When | What we do |
| --- | --- |
| Within 3 working days | Confirm we received the report. |
| Within 10 working days | Tell you whether we can reproduce it and how serious we think it is. |
| Before the fix ships | Agree a disclosure date with you, normally within 90 days of the report. |
| When the fix ships | Publish a security release and a GitHub security advisory, crediting you unless you'd rather we didn't. |

We may ask for a CVE through GitHub for serious issues. Please keep the details private until the advisory is published.

## Supported versions

| Version | Security fixes |
| --- | --- |
| The latest minor release | Yes |
| The minor release before it | Yes, for 3 months after the newer one ships |
| Older releases | No: please update |

Before 1.0, only the latest release receives fixes.

## How Kestello protects sites

- Releases are signed (Ed25519). A site installs an update only when its signature matches a trusted key, and the release page lists each zip's SHA-256 checksum.
- Updates and package installs are staged and checked before anything changes, with a backup first.
- The [security review checklist](.agents/skills/security-review/SKILL.md) is applied to changes that touch authentication, input, files, outbound HTTP or packages.

## Scope

In scope: this repository (core, the base theme, the example plugins and themes) and the release files published from it.

Out of scope: plugins and themes from other publishers (report to them), problems that need an administrator to act against their own site, missing hardening headers without a demonstrated impact, and denial of service through volume alone.

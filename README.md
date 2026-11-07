<p align="center"><img src=".github/banner.svg" alt="Kestello" width="640"></p>

Kestello is a content management system for the people who run a website and the developers who build it. Pages are written in a visual editor that shows the real page; plugins, themes, forms, media, search, backups and updates are built in. It is written in PHP and Vue with no Composer dependencies.

- **Documentation:** the [Kestello docs](https://docs.kestello.com) (guides for site owners, and pages for developers).
- **Releases:** [GitHub releases](https://github.com/kestellohq/cms/releases). A Kestello site checks them for updates and installs signed releases itself.

## Requirements

PHP 8.4 or newer (with `json`, `mbstring`, `pdo` and `sodium`), and MySQL 8, MariaDB 10.6, PostgreSQL 13 or SQLite 3.35. See [Requirements](https://docs.kestello.com/guide/requirements) for the details.

## Run it locally

You need Docker and Node.js 22.12 or newer.

```bash
cp .env.example .env
docker compose up -d
npm ci
```

Open http://localhost:8080 and follow the installer. The development commands (`npm run format`, `npm run lint`, `npm test` and the PHP tests) are described in [CONTRIBUTING.md](CONTRIBUTING.md).

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening an issue or a pull request: it covers the branches, commit messages, pull requests, tickets and releases. AI coding assistants follow [AGENTS.md](AGENTS.md). Report security problems privately, as described in [SECURITY.md](SECURITY.md).

## License

Kestello is free software, released under the [GNU General Public License, version 2 or later](LICENSE).

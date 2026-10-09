# Kontor

![Kontor — modular business operations for ProcessWire](assets/kontor-doodle.png)

Kontor is an open-source ERP, CRM and business-operations suite built natively for ProcessWire. Its 35 components share one audited core and one permission-aware admin workspace while remaining independently installable.

**Author:** [Maxim Semenov](https://github.com/mxmsmnv)<br>
**Website:** [smnv.org](https://smnv.org/)<br>
**Support development:** [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) · [smnv.org/sponsor](https://smnv.org/sponsor/)

## What Kontor Does

- **Customers and revenue:** contacts, companies, CRM intake, leads, pipelines, catalog, quotations, orders, invoices, payments and allocations.
- **Operations:** inventory, purchasing, expenses, projects, tasks, collaboration, files, documents, mail and customer portal.
- **Finance and localization:** double-entry ledger foundations and German business-document support.
- **Platform:** queue, cache, search, reports, dashboards, workflows, automation, custom entities and settings migration.
- **Integration:** authenticated REST resources, OpenAPI, webhooks, GraphQL and a scoped MCP provider for agent workflows.
- **Optional intelligence:** provider-neutral AI summaries, drafting and extraction with approval boundaries.

The root `Kontor` module owns the service container, migrations, audit, backups, import/export and component registries. `ProcessKontor` composes the admin experience from domain traits under `src/ProcessKontor/Traits/`; business rules remain in their owning packages.

## Admin Area

After installation, **Admin → Kontor** provides a single responsive workspace. Navigation only exposes installed components for which the current user has permission. Administrators can inspect component health, audit activity, backups, data exchange and settings migration without bypassing package ownership or organization boundaries.

## Public and Integration Surfaces

Kontor does not take over a site's frontend. A site profile composes public pages with documented module services. Optional integration surfaces are:

- REST: `/api/kontor/v1/*`
- GraphQL: `/graphql`
- customer portal routes supplied by `KontorPortal`
- 13 scoped MCP tools supplied by `KontorMCP`

Exact PHP calls, stability rules and examples are documented in [API.md](API.md). Agent and site-building boundaries are documented in [AGENTS.md](AGENTS.md).

## Requirements

- ProcessWire 3.0.240 or newer
- PHP 8.2 or newer
- required PHP extensions: DOM, JSON and ZIP
- a ProcessWire-supported database; the automated suite covers SQLite plus database-gated contracts

## Installation

1. Copy the repository to `site/modules/Kontor/`.
2. Run `composer install --no-dev` in that directory.
3. In ProcessWire Admin, choose **Modules → Refresh** and install **Kontor**.
4. Install only the component modules required by the approved site Blueprint; ProcessWire enforces declared module dependencies.
5. Assign the smallest required `kontor-*` permissions to each role and verify anonymous, member, editor and administrator paths.

The distribution includes a root-level discovery wrapper for every package module. Keep these `Kontor<Name>/Kontor<Name>.module.php` directories beside `packages/` when copying or packaging the repository; they let ProcessWire discover all 34 optional component modules while Composer continues loading their implementation from `packages/`. The wrappers are ordinary PHP files and do not rely on symbolic links, so the same release works on Unix and Windows hosts.

Upgrades are migration-driven. Ordinary uninstall removes module wiring but deliberately preserves business tables and stored data. Back up and explicitly plan any later data removal.

## Development and Testing

```bash
composer install
composer validate --strict
vendor/bin/phpunit
```

The release-candidate procedure, package matrix, persistent local ProcessWire environment and evidence are in [TESTING.md](TESTING.md) and [E2E_STATUS.md](E2E_STATUS.md). External mail, payment, webhook, AI and storage services must be replaced by deterministic local fakes during tests.

## Components

Kontor 1.0.0 contains 35 packages: AI, API, automation, cache, catalog, collaboration, contacts, CRM intake, CRM, dashboard, demo, documents, custom entities, expenses, files, Germany, GraphQL, inventory, invoices, ledger, mail, marketplace, MCP, payments, portal, projects, purchasing, queue, reports, sales, SDK, search, settings, tasks and workflow.

See [docs/PACKAGES.md](docs/PACKAGES.md) for responsibilities and dependency relationships, and [docs/KONTOR-SPECIFICATION.md](docs/KONTOR-SPECIFICATION.md) for the architecture.

## Documentation

- [API.md](API.md) — supported module calls, hooks, routes, errors and examples
- [AGENTS.md](AGENTS.md) — Olivia/agent operating and approval rules
- [EXAMPLES.md](EXAMPLES.md) — known-good ProcessWire integration examples
- [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) — local development and recovery CLI
- [CHANGELOG.md](CHANGELOG.md) — first public release

## License

Kontor is released under the [MIT License](LICENSE). © 2026 Maxim Semenov.

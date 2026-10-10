# Testing Plan

## Scope

- Project: `Kontor`
- Classification: D (complex product/platform)
- Test owner: Kontor maintainers
- Supported ProcessWire versions: 3.0.240+
- Supported PHP versions: 8.2+
- Uninstall data policy: `preserve-user-data`

## Risk summary

Kontor stores organization-scoped customer, commercial, operational and
financial data across independently installable components. Its highest-risk
boundaries are cross-component state transitions, tenant and permission
isolation, irreversible financial history, private files, public API and portal
routes, async work, and external mail/payment/AI/webhook providers.

Most combinatorial coverage belongs in package unit, integration and contract
tests. Browser coverage is intentionally limited to representative complete
journeys and access isolation.

## Test commands

```bash
# PHP syntax for shipped PHP
find . -path './vendor' -prune -o -path '*/vendor' -prune -o \
  -name '*.php' -print0 | xargs -0 -n1 php -l

# Unit tests (DB-gated tests skip when the variables are absent)
vendor/bin/phpunit

# Integration and migration tests against a dedicated disposable database
KONTOR_TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=kontor_test' \
KONTOR_TEST_DB_USER='kontor' KONTOR_TEST_DB_PASS='kontor' \
vendor/bin/phpunit --testsuite integration,migration

# Focused CRM Intake cross-package journey
KONTOR_TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=kontor_test' \
KONTOR_TEST_DB_USER='kontor' KONTOR_TEST_DB_PASS='kontor' \
vendor/bin/phpunit tests/Integration/CRMIntakeJourneyTest.php

# Install the browser runner and its local browser binaries once
npm ci
npx playwright install chromium firefox webkit

# Prepare persistent, named fixtures in a dedicated local ProcessWire site
KONTOR_E2E_SITE='/absolute/path/to/local-processwire' \
KONTOR_E2E_STATE='/tmp/kontor-e2e-state.json' \
KONTOR_E2E_EDITOR_PASS='<local-test-password>' \
KONTOR_E2E_RESTRICTED_PASS='<local-test-password>' \
php tests/e2e/fixtures.php

# Run the complete matrix, or the engines available on this macOS host
KONTOR_E2E_STATE='/tmp/kontor-e2e-state.json' \
KONTOR_E2E_EDITOR_PASS='<local-test-password>' \
KONTOR_E2E_RESTRICTED_PASS='<local-test-password>' npm run test:e2e
KONTOR_E2E_STATE='/tmp/kontor-e2e-state.json' \
KONTOR_E2E_EDITOR_PASS='<local-test-password>' \
KONTOR_E2E_RESTRICTED_PASS='<local-test-password>' npm run test:e2e:available
```

The Playwright runner intentionally mutates the qualification journey only in
the desktop Chromium project, then repeats the permission, responsive and axe
checks in the remaining browser/device profiles. It retains the local site and
named fixture records between runs; generated traces, videos and reports live
under ignored `artifacts/e2e`. Every journey fails on console errors, uncaught
page errors, failed requests or HTTP responses at or above 400. A successful
PHPUnit run alone is not an E2E pass.

## Test environment

- Development site: a disposable ProcessWire 3.0.240+ installation; never a
  production site
- Database isolation: a unique disposable database or dedicated test tenant
- Administrator account: `E2E admin` fixture (password is environment-local)
- Member/editor accounts: `E2E member` and `E2E editor` fixtures with explicit
  Kontor permissions
- External service fakes: local mail catcher, demo payment provider, mock HTTP
  server, local/private filesystem adapter and deterministic AI provider
- Fixture prefix: `E2E`

## ProcessWire boundary coverage

- [x] Module discovery and metadata, including the namespace ProcessWire parses
  from every shipped root-level release wrapper
- [x] Fresh Core install followed by dependency-ordered component install
- [x] Configuration defaults and save (no module implements configurable
  module state; Kontor Settings migration is tested separately)
- [x] Missing and incompatible dependencies
- [x] Documented public APIs and hooks: exact documented method references,
  Contacts REST request pipeline, SDK/event envelopes, Settings/MCP contracts
  and operational boot registrations are locked by deterministic tests;
  ProcessWire-only wrappers are covered by the live-site boundary
- [x] Permissions and organization isolation
- [x] Data save and reload
- [x] Historical v001 schema/data upgrade through every declared current
  migration and component upgrade hook
- [x] Uninstall preserves business data
- [x] Reinstall reconnects preserved data

## Critical automated journeys

### Journey 1: qualification to deal

- Role: CRM editor
- Starting state: Contacts, CRM and CRM Intake installed; an active intake
  profile; a default deal pipeline with an open stage
- Actions: create a contact, create a linked lead, capture required intake
  answers, qualify the lead and convert it to a deal
- Expected UI result: contact, lead and deal workspaces show the configured
  human-readable fields and the conversion ends on the new deal
- Expected stored result: one response per entity; fields targeting deals are
  copied, contact-only fields are not; the bound source remains consistent
- Access/security assertion: a user without CRM Intake administration cannot
  manage the profile; a user without deal creation or the dedicated lead
  conversion permission cannot convert
- Cleanup: retain the named fixture records in the persistent acceptance site

### Journey 2: representative order-to-cash

- Role: sales/finance editor
- Starting state: the connected demo dependencies are installed with demo
  providers only
- Actions: customer to quotation, order, invoice, demo payment and allocation
- Expected UI result: every handoff links to the resulting record
- Expected stored result: totals and state transitions agree across packages
- Access/security assertion: ordinary users cannot perform restricted finance
  corrections or view another organization
- Cleanup: discard the database

### Journey 3: settings migration without business data

- Role: Kontor administrator
- Starting state: Settings and CRM Intake installed; profile and captured
  answers exist
- Actions: export, validate-only preview and confirmed import into another
  disposable tenant
- Expected UI result: the profile is previewed and imported
- Expected stored result: profile definitions transfer; captured answers,
  credentials and secrets do not
- Access/security assertion: non-administrators are denied
- Cleanup: discard both tenants/databases

## Agent-led release scenarios

### Administrator session

- [x] Install Core and selected components in dependency order
- [x] Configure a CRM Intake profile and inspect health/components/settings
- [x] Verify uninstall warning and preserved-data policy without using live data

### Member/editor session

- [x] Complete the qualification-to-deal journey
- [x] Confirm permitted navigation excludes unavailable or forbidden components

### Anonymous session

- [x] Kontor admin and API routes deny access without leaking record data

### Cross-role or multi-user scenario

- [x] Administrator configures a profile; editor uses it; restricted member
  cannot manage it or convert the lead

### Presentation

- [x] Representative desktop viewport
- [x] Representative mobile viewport
- [x] Keyboard/accessibility smoke
- [x] Light and dark admin themes
- [x] Browser console and failed network requests inspected

## Failure paths

- [x] Invalid and missing intake values
- [x] Unauthenticated access
- [x] Unauthorized role and cross-organization identifiers
- [x] CSRF failure on every mutation
- [x] Duplicate submission or webhook replay
- [x] External timeout/failure
- [x] Retry and idempotency
- [x] Empty state
- [x] Large or boundary data state
- [x] Logs contain no secrets or unnecessary personal data

## External services

| Service | Test substitute | Live test policy |
|---|---|---|
| Mail | local catcher/in-memory transport | no live delivery in ordinary tests |
| Payments | demo gateway | explicitly authorized test credentials only |
| Webhooks/API clients | local mock HTTP server | no production endpoints |
| AI | deterministic fake provider | explicitly authorized test provider only |
| Private files/object storage | disposable local or S3-compatible store | no production buckets |

## Cleanup

The reusable ProcessWire sites, their databases and named E2E fixtures are
intentionally retained between runs. Only transient databases, servers and
browser sessions are removed after each checkpoint.

- [x] Dedicated test settings and named fixtures intentionally retained
- [x] Persistent `E2E` users/data retained in the dedicated databases
- [x] Queue jobs completed or removed safely
- [x] Browser workers, servers and watchers stopped
- [x] No real external side effects occurred

## Release evidence

Record the commit, installed module/component versions, ProcessWire/PHP/database
versions, profile (`change validation`, `pull request`, `nightly` or `release
candidate`), commands and results, roles/sessions, completed journeys, defects,
blocked areas, cleanup state and failure artifacts.

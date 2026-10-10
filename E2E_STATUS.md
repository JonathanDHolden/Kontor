# Kontor release-candidate test status

Last updated: 2026-10-10

This is the durable checkpoint for the current full-system test programme. It
records verified evidence, defects fixed during the run and work that still
requires a later compatibility or external-service pass. It does not treat a
browser route smoke as a substitute for package-level coverage.

## Environment

- Source: merged `main`, including the configurable CRM Intake release
  candidate, portable SQL work and focused regressions listed below
- Runtime: PHP 8.5.8, ProcessWire 3.0.259, MariaDB, local PHP development server
- Site: disposable local installation with its own database
- Lifecycle site: persistent clone with an independent database for destructive
  install/upgrade/uninstall verification; the primary test site was not mutated
- Components: all 35 Kontor components installed and enabled; MCP Server was
  installed as the explicit external dependency of Kontor MCP
- External side effects: none; no production mail, payment, AI, webhook or
  object-storage endpoint was used

## Automated evidence

### 1.1.1 component-discovery regression

A public installation report from ProcessWire 3.0.274 on PHP 8.4 exposed that
the 34 root-level discovery wrappers loaded their package implementation but
did not declare `namespace ProcessWire`. ProcessWire determines the expected
class namespace from the discovered wrapper itself, so it looked for a global
class and marked every optional component as not implementing
`ProcessWire\Module`.

All 34 wrappers now declare the namespace before loading their implementation.
The regression contract checks every shipped wrapper, and the persistent local
ProcessWire environment independently parsed every release wrapper with the
real module loader and resolved 34/34 to classes implementing `Module`. The
environment files and database were retained.

| Area | Result |
|---|---:|
| Core/root PHPUnit | 342 tests, 2,636 assertions |
| Contacts, CRM, intake, catalog, sales, invoices, payments, portal | 308 tests, 850 assertions |
| Inventory, purchasing, expenses, projects, tasks, collaboration, workflow, automation, documents, ledger, Germany | 208 tests, 499 assertions |
| SDK, API, GraphQL, files, mail, queue, cache, settings, MCP, marketplace, dashboard, reports, search, entities, AI | 421 tests, 954 assertions |
| Total | 1,279 tests, 4,939 assertions |

Four Redis-specific cache tests were skipped because the optional `ext-redis`
extension is not installed. The in-memory cache implementation and the rest of
the cache package passed. A final syntax pass checked 1,048 PHP source and test
files; all 35 Kontor manifests and all 36 Composer manifests parsed
successfully.

A repository-wide dependency contract now verifies that every ProcessWire hard
dependency is also present in the owning package's Composer runtime graph. It
found and closed missing Queue, Files and Cache requirements in Collaboration,
Documents, Dashboard and Search respectively; all Composer manifests validate.
Missing, incompatible package and incompatible ProcessWire versions are covered
by fail-closed dependency tests. The persistent lifecycle site also refused to
uninstall Cache while Dashboard and Search depended on it, then remained at
35/35 installed components.

A source inventory confirmed that none of the 36 ProcessWire modules implements
`ConfigurableModule` or module-owned configuration fields, so there is no
module configuration-default/save boundary to exercise. Portable workspace
settings remain owned by Kontor Settings and its separate migration journey.

A deterministic webhook transport fake now covers timeout persistence, retry
state, replay with the same delivery identifier and successful recovery without
creating a duplicate delivery. No network endpoint was contacted.

A deterministic payment-provider fake now covers an ambiguous timeout,
redacted failure persistence, rejection of idempotency-key drift, same-key
recovery and replay after success without another provider call. An object
storage fake likewise covers write-then-timeout cleanup, metadata-persistence
failure cleanup and corrected retry without duplicate objects or leaked
credentials/content.

A historical v001 fixture now covers all 36 ProcessWire modules, 76 current
migrations and 77 package-declared storage tables. It restores the historical
schema, preserves a business record while applying the one post-v001 migration,
and verifies every current migration is idempotent. The inventory exposed and
closed missing Catalog and Queue upgrade hooks.

A repository-wide public-contract audit checked every exact documented
`Class::method()` reference against a public implementation. New deterministic
coverage exercises the Contacts REST request pipeline with auth, idempotency,
projection and tenant isolation; canonical SDK value/event envelopes; every
Settings service method; all 13 MCP declarations plus search, record and
settings bridge behavior; documented operational methods and the Automation
and Germany boot registrations. CRM lifecycle event names, ordering, actors and
payloads are also locked. No broken documented method name was found.

A tokenizer-backed CSRF contract now inventories all 168 ProcessKontor execute
handlers: 50 are explicitly read-only and all 118 mutation-capable handlers
must call the shared POST/ProcessWire CSRF guard. It exposed and closed missing
guards in the inline Contact and Company save branches.

Boundary and provider-failure regressions now reject cross-currency payment
allocations before any row is written and prove that an AI timeout containing a
credential and confidential prompt returns only a stable redacted error before
a deterministic retry succeeds.

A deterministic mail transport regression now proves that a failed delivery
can be retried on its original history row, that retrying a sent message is an
idempotent no-op and that recipient, body and credential text from a transport
exception do not reach persisted errors or failure events. A real-database
settings-migration journey exports, previews and imports a CRM Intake profile
between tenants while excluding captured answers, record identifiers and
credential-like answer content. Template-level failure-path coverage also
verifies the permission-aware Expenses empty state.

## ProcessWire and browser evidence

- Fresh ProcessWire installation completed and all 35 Kontor components were
  installed in dependency order.
- Components reported 35 registered, 35 enabled and zero needing attention.
- Health reported 32 healthy, two warnings and zero critical. The warnings were
  expected configuration states: an unsynchronised official marketplace
  registry and no default CRM Intake profile.
- Forty admin routes rendered without a server or template error: dashboard,
  contacts, companies, CRM, intake, deals, sales, invoices, payments, tasks,
  collaboration, reports, inventory, purchasing, expenses, projects,
  workflows, demo, automations, mail, portal, files, cache, documents, AI,
  ledger, Germany, marketplace, GraphQL, API, custom entities, search, catalog,
  activity, backups, health, organization, settings migration, queue and
  components.
- A real connected demo journey completed in the browser: create scenario,
  prepare proposal, request approval, approve, start delivery, issue invoice
  and record full payment. The result was completed/settled with 21 linked
  records, a paid invoice, closed task and balanced ledger.
- A CRM editor completed the critical customer qualification journey in the
  real ProcessWire UI: created a contact, captured configured intake answers on
  a linked lead, qualified it and converted it into a deal. The deal retained
  the contact, source, description and 25,000 EUR value. Navigation exposed
  only the permitted Contacts, CRM, Dashboard and Quick Access components.
- A second role with lead edit and deal create but without the declared lead
  conversion permission could open the same qualified workflow but received no
  conversion action. The server-side endpoint has the matching fail-closed
  permission regression.
- A second demo scenario exercised the rejection path: proposal preparation,
  approval request and rejection with a required reason. The scenario returned
  to an active proposal, displayed the rejection note and offered a new
  approval request without creating delivery, invoice or payment records.
- Five real-database integration tests now cover the demo service's complete
  order-to-cash lifecycle, approval rejection/retry, denied transitions and
  transaction rollback for failed intake and transition effects.
- Six real-database CRM Intake validation tests cover invalid profile schemas,
  required and typed answers, normalization, unsupported entity types, missing
  profiles and same-entity tenant isolation.
- The lifecycle matrix uninstalled all 36 Kontor/ProcessWire modules in reverse
  dependency order, verified identical row counts, checksums and normalized DDL
  for all 84 `kontor_*` tables, reinstalled every module, ran every available
  upgrade hook and confirmed preserved data remained connected.
- The CRM Intake browser permission matrix passed: anonymous access returned
  the login boundary; a lead viewer could inspect the active profile but saw no
  management action; a user with intake administration but no Settings grant
  was denied the Settings route and no longer sees a dead-end action; a fully
  authorized administrator could open Settings migration.
- CRM Intake passed a 375x812 responsive pass without horizontal overflow,
  keyboard focus reached both page actions, light and dark themes retained
  readable contrast, the console contained no warnings/errors, and a captured
  reload produced no failed requests or HTTP responses at or above 400.
- The qualification workspace also passed a styled 390x844 mobile check. After
  correcting the local PHP router so static ProcessWire assets were served
  normally, its reload produced 25 successful responses, no failed requests,
  no HTTP response at or above 400 and no new console warning or error.
- A restricted automation/workflow user could see definitions but not the
  seeded execution-log sentinel or workflow history. An administrator could
  see both.
- A restricted purchasing/expense user could view order and expense queues but
  not a seeded supplier or expense category. Giving create permissions without
  the corresponding supplier/category view permission still denied direct
  create-form access at the missing reference-data permission.
- The committed Playwright runner passed the full qualification/conversion and
  restricted-role journeys in desktop Chromium. The restricted journey also
  passed Pixel 7 Chromium and iPhone 15 WebKit profiles, including horizontal
  overflow and serious/critical axe checks. The committed runner now also fails
  every journey on console errors, uncaught page errors, failed requests or
  HTTP responses at or above 400. These checks exposed and closed insufficient
  contrast for default labels and field notes.

## Defects fixed in this run

- Backup restore order and content-integrity verification.
- Cross-organization portal account/profile association and legacy health
  diagnostics.
- Automation-log and workflow-history permission leakage.
- Supplier-directory and expense-category permission leakage, including direct
  form access.
- Collaboration and Documents dependency manifest drift.
- Stale Invoices documentation for order-to-invoice conversion.
- Narrow-screen contact action wrapping and CRM Intake badge/title overlap.
- Demo transition effects running before the workflow permission check.
- CRM Intake profiles accepted blank or storage-overflowing names when callers
  used the public service directly instead of the settings adapter.
- Primary page-header links could inherit UIKit link-reset text color over a
  dark primary background, making the CRM Intake action label invisible.
- CRM Intake showed Settings migration to intake administrators who lacked
  both Settings export and import permissions, leading to a guaranteed denial.
- Creating a task with a contact or company relation did not enforce the
  declared relation-management permission before persisting the task.
- Customer file listing and signed-download generation did not independently
  enforce the current organization when used through the Portal service.
- Four package Composer manifests omitted ProcessWire-declared runtime
  dependencies, allowing incomplete standalone dependency resolution.
- Inline Contact and Company saves processed submitted fields without the
  shared POST and CSRF guard used by the other mutation handlers.
- Cross-currency payment allocation reached persistence before failing during
  invoice synchronization.
- The external AI adapter exposed raw client exception messages that could
  contain provider credentials, URLs or prompt content.
- Lead conversion ignored the declared `kontor-crm-lead-convert` permission in
  both its UI action state and mutation endpoint.
- Raw mail transport exceptions could persist recipient, message-body or
  credential content, and failed deliveries had no same-row retry path.
- An empty Expenses workspace shown to a user without category access offered
  misleading zero metrics and an unavailable first-expense action instead of
  explaining the permission blocker.
- Test assumptions that depended on MySQL JSON formatting, unordered query
  results or unread PDO result sets.
- Object storage could write bytes and then time out, leaving an orphan while
  exposing provider exception details; compensation and redaction now cover
  both the storage and metadata failure boundaries.
- Payment provider retries had no provider-neutral idempotent capture contract.
- Catalog and Queue lacked upgrade hooks to synchronize their component
  registry versions.
- Default UIKit labels and field notes did not meet serious axe contrast checks
  in the Kontor shell.

## Remaining release-matrix work

The current disposable run is a strong release-candidate pass, not a claim that
every supported environment and real provider has been exercised. The
following remain explicit:

- run the Firefox profile on a compatible host: Playwright Firefox Nightly exits
  before navigation on this macOS host with `Could not find profile folder`;
  desktop/mobile Chromium and mobile WebKit pass;
- exercise the Redis adapter on a host with optional `ext-redis`; its four
  adapter-specific tests are the only skipped PHP checks, while the in-memory
  implementation passes;
- 32 of the 35 component packages still lack a formal `API.md`, and none has an
  `EXAMPLES.md`; their README references were verified, but undocumented module
  façade methods cannot be claimed as a stable public API until maintainers
  define that contract;
- thin ProcessWire HTTP/LazyCron hook wrappers and module factory accessors are
  evidenced by the live all-components site rather than isolated package
  tests. The Contacts resource-to-GraphQL composition remains a cross-package
  contract gap; generic GraphQL execution and the resource itself both pass;
- the specification manifest mentions `crm.lead.created`, while the current
  CRM architecture has no lead-creation application service/event boundary.
  The implemented and README-documented deal/lead-conversion events are covered;
  an event was not invented at repository persistence level.

Portal file access is now organization-scoped in the service itself. Within one
organization, customer/contact ownership remains deliberately enforced by the
Portal composition boundary: `ProcessKontor` first resolves quotations and
invoices through contact-scoped repositories and only then lists and signs
their files. Moving that customer concept into the generic file-signing service
would require a public API redesign and is recorded as an explicit architectural
boundary rather than silently duplicating Sales and Invoices ownership rules.

Every package now has automated evidence plus live ProcessWire boundary
evidence, or a precise justified host/documentation gap above. This completes
the current release-candidate programme; the persistent environments remain
available for the next compatibility or release run.

## Cleanup

The agent-controlled browser tab and local PHP server were stopped after the
run; no test worker or watcher was left running. At the owner's request, the
`/Users/mas/dev/processwire/e2e/kontor-full` site and its `kontor_e2e_full`
database are now a persistent reusable test environment. It currently has one
organization and all 35 Kontor components installed. Future runs must preserve
its files and database while continuing to remove only short-lived per-suite
verification databases and stop idle processes. The independent lifecycle
clone at `/Users/mas/dev/processwire/e2e/kontor-lifecycle` and database
`kontor_e2e_lifecycle` are also retained for repeatable destructive module
matrix runs; its final state is fully reinstalled.

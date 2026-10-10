# Kontor Public API

This document describes the supported public integration surface for Kontor 1.1.1 (ProcessWire module version 111). The installed code and live site state still determine availability.

## Module Access

Feature-detect optional modules before use:

```php
<?php namespace ProcessWire;

if (!$modules->isInstalled('Kontor')) {
    throw new WireException('Kontor is not installed.');
}

/** @var Kontor $kontor */
$kontor = $modules->get('Kontor');
$container = $kontor->container();
```

Within ProcessWire module classes prefer `$this->wire()->modules->get('ModuleName')`. A module method may throw a domain exception, `WireException`, `InvalidArgumentException`, `RuntimeException` or a persistence exception; callers must not treat failure as an empty successful result.

## Stable Module Facades

The following public module methods are the supported PHP entry points. Returned services and repositories enforce their own validation and organization scope.

| Module | Public facade methods |
| --- | --- |
| `Kontor` | `container()` |
| `KontorContacts` | `contactRepository()`, `companyRepository()`, `addressRepository()`, `membershipRepository()`, `duplicateDetector()`, `tagService()` |
| `KontorCRM` | `leadRepository()`, `dealRepository()`, `pipelineRepository()`, `stageRepository()`, `crmService()`, `kanbanBoard()` |
| `KontorCRMIntake` | `profileRepository()`, `responseRepository()`, `service()` |
| `KontorCatalog` | `itemRepository()`, `categoryRepository()`, `priceListRepository()`, `priceRepository()`, `priceListDuplicator()` |
| `KontorSales` | `quotationRepository()`, `orderRepository()`, `documentLineRepository()`, `quotationWorkflow()`, `orderWorkflow()`, `conversionService()` |
| `KontorInvoices` | `invoiceRepository()`, `documentLineRepository()`, `workflow()`, `orderConversionService()` |
| `KontorPayments` | `paymentRepository()`, `allocationRepository()`, `invoiceRepository()`, `allocationService()`, `workflow()` |
| `KontorInventory` | `warehouseRepository()`, `balanceRepository()`, `movementRepository()`, `barcodeRepository()`, `movements()` |
| `KontorPurchasing` | supplier, purchase-order, document-line, receipt and receipt-line repositories; `purchaseOrderWorkflow()`, `goodsReceipt()` |
| `KontorExpenses` | `categoryRepository()`, `expenseRepository()`, `workflow()`, `workflowCoordinator()` |
| `KontorProjects` | project, milestone, time-entry and billable-item repositories; `timeTracking()`, `milestones()`, `invoicing()` |
| `KontorTasks` | `taskRepository()`, `reminderRepository()`, `workflow()`, `reminders()`, `reminderDispatcher()`, `relations()` |
| `KontorCollaboration` | note, comment, mention, follower and unread-state repositories; `commentService()`, `notificationDispatcher()`, `unreadStateService()` |
| `KontorWorkflow` | definition, transition, instance, approval-request and history repositories; `definitions()`, `engine()` |
| `KontorAutomation` | rule, condition, action and execution-log repositories; `actionHandlerRegistry()`, `definitions()`, `engine()` |
| `KontorDocuments` | `templateRepository()`, `templateManager()`, `renderService()`, `snapshotBuilder()` |
| `KontorFiles` | `storage()`, `fileManager()`, `verifyTemporaryUrl()` |
| `KontorMail` | `mailboxRepository()`, `messageRepository()`, `inboundAdapterRegistry()`, `mailboxes()`, `outbound()`, `outboundWithSender()`, `inbound()`, `entityLinking()` |
| `KontorPortal` | account/quotation/invoice repositories; `authentication()`, `payments()`, `files()`, `fileDownloadHandler()`, `profile()` |
| `KontorReports` | `providerRegistry()`, `scheduledReportRepository()`, `reportBuilder()`, `chartDataMapper()`, `reportExporter()`, `scheduledReports()`, `scheduledReportDispatcher()` |
| `KontorDashboard` | `dashboardRepository()`, `widgetRepository()`, `widgetRegistry()`, `dashboardService()` |
| `KontorSearch` | `providerRegistry()`, `globalSearchService()` |
| `KontorQueue` | `queue()`, `worker()`, `jobRegistry()` |
| `KontorCache` | `store()`, `manager()` |
| `KontorSettings` | `providerRegistry()`, `migrationService()` |
| `KontorAPI` | token/webhook/idempotency repositories, `resourceRegistry()`, `authenticator()`, `openApiGenerator()`, `requestHandler()` |
| `KontorGraphQL` | `schemaRegistry()`, `executor()`, `requestHandler()` |
| `KontorEntities` | definition, field, record and view repositories; `builder()`, `records()`, `views()`, `relations()`, `schema()` |
| `KontorMarketplace` | registry, publisher, listing and advisory repositories; registry management, sync, advisory and installability services |
| `KontorAI` | `pendingActionRepository()`, `providerRegistry()`, gateways, summary/drafting/extraction services and approval services |
| `KontorLedger` | account, entry and line repositories; `chartOfAccounts()`, `entries()`, `balances()` |
| `KontorGermany` | `localizationProvider()`, `chartOfAccountsSeeder()`, `documentFormatter()` |
| `KontorMCP` | `mcpProviderInfo()`, `mcpTools()`; tool callbacks are invoked by the MCP host |

Methods not listed here should be treated as unstable until verified and documented. Exact signatures are authoritative in the installed module version.

`KontorDocuments::renderService()` supports `renderBody()`, `renderPreviewHtml()`, `renderPdf()`, and `fields()`. Shared sections use `{{> template.key}}`; resolution is restricted to the current organization and requested template language (with the documented English fallback), rejects missing or circular sections, and is capped at three nested includes.

## HTTP and Agent Surfaces

- REST is mounted at `/api/kontor/v1/*`. Bearer tokens, declared resource operations, field projection, filtering, idempotency and organization scope are enforced by `KontorAPI`.
- GraphQL is mounted at `/graphql`. The schema contains only registered component types and applies permission and complexity limits.
- `KontorPortal` owns its configured public customer routes and signed file access.
- `KontorMCP` exposes 13 tools for status, components, resources, bounded search/list/get/validate/create/update/archive operations, settings export/preview/apply and API description. Mutations require the declared scope and idempotency key; archive and settings apply require explicit confirmation.

Do not construct HTTP responses by calling hook handlers directly. Use the service facade for in-process work or the public route for remote clients.

## Events and Hooks

Components publish `KontorEvent` envelopes through Core's event dispatcher. Event names and payload schemas declared by an installed component's `kontor.json` are its contract. Subscribe through the dispatcher resolved from `Kontor::container()`; do not hook private methods or infer payloads from audit text.

ProcessWire lifecycle hooks such as `ProcessPageView::execute` and the `ProcessKontor` admin execute methods are adapter internals, not site API.

## Configuration and Permissions

Configuration belongs to the owning module. Portable, secret-free settings may be exported, previewed and applied through `KontorSettings`; secrets are never part of a portable profile. Every user-facing operation needs `kontor-access` and its documented component permission. REST tokens, GraphQL and MCP add their own scopes. Permission checks do not replace organization and record-ownership checks.

## Transactions, Idempotency and Side Effects

Use the owning application service for workflows that span repositories. Do not manually reproduce its SQL. REST and MCP mutations require stable idempotency keys where documented. Queue, mail, webhook, payment, AI and external-storage operations can create external effects: configure deterministic fakes in development and require explicit approval before enabling a live provider.

## Supported Examples

See [EXAMPLES.md](EXAMPLES.md) for feature-detected Contacts, Search, Queue and Settings examples. Examples deliberately avoid hard-coded internal IDs and direct SQL.

## Internal and Unsupported APIs

The following are not public contracts: `ProcessKontor->___execute*()` methods, the traits in `src/ProcessKontor/Traits/`, private/protected methods, migration classes, concrete table names, direct SQL, admin form builders and package test helpers. Do not instantiate repositories with another organization's identifiers or bypass validation, permissions, CSRF, ownership, idempotency or audit services.

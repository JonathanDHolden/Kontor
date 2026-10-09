# Kontor Documents

`kontor/documents` — document templates, HTML/PDF rendering, multilingual
output, and immutable issued-document snapshots. Fifth business component
(Substage 4.2). Depends on `kontor/sdk` + `kontor/core` and `kontor/files`:
rendered PDFs are persisted as private, versioned files together with their
immutable snapshots. Nothing here needs Contacts, Catalog, or Sales to exist.

## A schema gap, filled inside this package

kontor.md section 26 lists `KontorDocuments`' features but — like Cache and
Search before it — the spec has no dedicated DB schema section for it.
`kontor_documents_templates` is this substage's gap-fill, not a Core
addition this time (unlike `ExtensionRepository`/`ReportProviderRegistry`/
`SequenceService` in earlier substages): nothing outside Documents needs
this table, so it lives entirely in this package.

## Contents

- `migrations/` — `kontor_documents_templates`. Versions of "the same
  template" are modeled exactly like `kontor_files` (kontor.md#11.6):
  separate rows sharing a family key — here
  `(organization_id, template_key, language)` — with an incrementing
  `version_number`; publishing a new version archives the previous row
  rather than deleting it. `kontor_sales_quotations.template_uid` (and the
  same column on invoices, Substage 4.3) points at one specific row's
  `uid`, not at `template_key` — so an already-issued document keeps
  rendering from the exact version it was issued against even after a
  newer version is published.
- `src/Infrastructure/Rendering/TemplateEngine.php` — the "document
  designer v1" milestone: a deliberately small templating language over
  plain HTML — `{{field}}` / `{{field.nested}}` value interpolation
  (HTML-escaped), `{{#each list}}...{{/each}}` repeating sections (for
  document lines), and `{{#if field}}...{{/if}}` conditional sections
  (kontor.md#26). Page breaks and layout are just literal CSS the template
  author writes — the engine doesn't need to know about them.
- Shared sections use `{{> template.key}}`. `DocumentRenderService` resolves
  them through the repository for the same organization and requested
  language, preserving the English fallback. Missing sections and cycles fail
  closed, and nesting is limited to three includes. `fields()` returns unique
  non-row placeholders in display order after expanding shared sections.
- `src/Infrastructure/Rendering/PdfRenderer.php` — wraps
  [`dompdf/dompdf`](https://github.com/dompdf/dompdf) for the "PDF"
  milestone. Chosen over `openspout`/`phpoffice`-style libraries because
  its Composer constraint (`^7.1 || ^8.0`) doesn't narrow Kontor's
  `php >=8.2` floor, and it needs no external binary — a pure Composer
  dependency is enough.
- `src/Application/TemplateManager.php` — the "templates" and
  "multilingual output" milestones: `publish()` always inserts a new
  version and archives the previous one for its `(key, language)` family;
  different languages of the same `template_key` version independently.
- `src/Application/DocumentRenderService.php` — turns a `DocumentTemplate`
  + data array into rendered body HTML, a standalone HTML preview
  document, or PDF bytes. Deliberately has no persistence dependency —
  resolving *which* version to render is `TemplateRepository`'s job
  (`findCurrentVersion()`, including the English-fallback rule below);
  this service only renders whatever template object it's handed.
- `src/Application/DocumentSnapshotBuilder.php` — the "snapshots" /
  "immutable issued output" milestone: builds the array a consuming
  component should store verbatim into its own `snapshot_json` column
  (kontor.md#15.1–15.3, present on quotations/orders/invoices but unused
  until now) at the moment a document is issued — rendered HTML plus the
  exact template identity and the data it was rendered from, so the
  snapshot stays reproducible even if the template is edited or archived
  afterward.
- The ProcessKontor render workflow stores every generated PDF through
  `KontorFiles`, binding its version family to the exact document-template
  UID and retaining the immutable snapshot in file metadata. Rendering the
  same template version again creates another file version instead of
  overwriting issued output.
- Letter and contract previews build their form from the expanded template's
  fields. When Contacts is installed, the user may bind output to a company;
  the private PDF is named from the template, company and date and is listed
  on that company's admin page. Existing invoice-shaped previews are unchanged.

## Multilingual output

`TemplateRepository::findCurrentVersion()` falls back to English when the
requested language has no published version for a `template_key` — the
same "English is always source and fallback" rule
`Kontor\Core\Infrastructure\Registry\TranslationRegistry` already uses
(kontor.md#23), applied to template content instead of UI strings.

## Testing

```bash
composer install
vendor/bin/phpunit
```

Unlike most packages in this monorepo, `tests/Unit/Infrastructure/PdfRendererTest.php`
and `tests/Unit/Health/DocumentsHealthCheckTest.php` need no database or
external service — dompdf is a pure-PHP Composer dependency — so they run
for real in this sandbox, not just skip cleanly. Persistence tests
(`tests/Integration/`) still need real MySQL (see
`../../docker-compose.test.yml`) and are skipped otherwise, same
`KONTOR_TEST_DB_DSN` convention as the other packages.

## Not in scope

QR codes and barcodes (kontor.md#26) are part of `KontorDocuments`' full
eventual feature list, not this substage's milestones (`templates; PDF;
multilingual output; snapshots; document designer v1`) — deferred, same as
every earlier component skipping its non-milestone spec features. No admin
UI/visual designer front-end — "document designer v1" here means the
template engine's capability set (placeholders, loops, conditionals), not
a WYSIWYG editor. `DocumentSnapshotBuilder` is wired into generated Files
output, Sales quotation issuance, and invoice/credit-note issuance. Order
`snapshot_json` remains for its own issue integration.

A first-class contract record (company, exact template version, submitted
values and draft/sent/signed lifecycle) remains a separate schema and workflow
feature. Version 1.1.0 deliberately does not invent that persistence model;
company-bound immutable PDFs provide the requested document relationship now.

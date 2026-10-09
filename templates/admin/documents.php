<?php

/** @var \Kontor\Documents\Domain\DocumentTemplate[] $templates */
/** @var \Kontor\Documents\Domain\DocumentTemplate|null $selected */
/** @var array<string, mixed>|null $preview */
/** @var bool $canCreate */
/** @var bool $canEdit */
/** @var bool $canArchive */
/** @var bool $canRender */
/** @var bool $filesReady */
/** @var string[] $templateFields */
/** @var \Kontor\Contacts\Domain\Company[] $companies */
/** @var bool $contactsReady */
/** @var string $adminUrl */
/** @var string $csrfName */
/** @var string $csrfValue */
/** @var callable $e */

$defaultBody = <<<'HTML'
<main>
  <h1>{{title}}</h1>
  <p>Customer: {{customer.name}}</p>
  <ul>{{#each lines}}<li>{{description}} — {{amount}}</li>{{/each}}</ul>
  {{#if note}}<p>{{note}}</p>{{/if}}
</main>
HTML;
$typeLabels = [
    'invoice' => 'Invoice',
    'quotation' => 'Quotation',
    'order' => 'Order',
    'credit_note' => 'Credit note',
    'letter' => 'Business letter',
    'contract' => 'Contract or agreement',
];
$languageLabels = ['en' => 'English', 'de' => 'German', 'fr' => 'French', 'it' => 'Italian'];
$editing = $selected !== null;
$editorAvailable = $editing ? $canEdit : $canCreate;
$documentType = $selected?->documentType ?? 'invoice';
$language = $selected?->language ?? 'en';
$documentTypeLabel = $typeLabels[$documentType] ?? ucwords(str_replace('_', ' ', $documentType));
$languageLabel = $languageLabels[$language] ?? strtoupper($language);
$libraryTemplates = array_values(array_filter(
    $templates,
    static fn ($template): bool => $selected === null
        || $template->uid->toString() !== $selected->uid->toString()
));
$dynamicPreview = in_array($documentType, ['letter', 'contract'], true);
$fieldLabel = static fn (string $field): string => ucwords(str_replace(['_', '.'], [' ', ' · '], $field));
$longField = static fn (string $field): bool => preg_match('/(?:_details|_description|_terms|_address)$/', $field) === 1;
?>
<div class="ProcessKontor pw-module-workspace kontor-shell">
  <header class="pw-module-head kontor-pagehead">
    <div>
      <p class="kontor-eyebrow"><?= $selected === null ? 'Document library' : 'Document template' ?></p>
      <h2><?= $e($selected?->name ?? 'Documents') ?></h2>
      <p><?= $selected === null
          ? 'Create reusable templates, publish controlled versions and produce consistent customer-ready documents.'
          : 'Preview the current version, create a PDF and publish improvements without changing previously issued documents.' ?></p>
    </div>
    <?php if ($editorAvailable): ?>
      <div class="pw-module-actions">
        <button class="uk-button uk-button-primary" type="button" uk-toggle="target: #kontor-document-editor">
          <i class="fa fa-<?= $editing ? 'pencil' : 'plus' ?>"></i>
          <?= $editing ? 'Edit template' : 'New template' ?>
        </button>
      </div>
    <?php endif; ?>
  </header>

  <?php if ($selected !== null): ?>
    <section class="uk-card uk-card-default uk-card-small uk-card-body uk-margin-medium-bottom">
      <div class="uk-grid-medium uk-flex-middle" uk-grid>
        <div class="uk-width-expand@m">
          <div class="uk-flex uk-flex-wrap uk-flex-middle uk-grid-small" uk-grid>
            <div><span class="uk-label<?= $selected->isArchived() ? '' : ' uk-label-success' ?>"><?= $selected->isArchived() ? 'Archived' : 'Active' ?></span></div>
            <div class="uk-text-meta"><?= $e($documentTypeLabel) ?> · <?= $e($languageLabel) ?> · Version <?= $e((string) $selected->versionNumber) ?></div>
          </div>
          <h3 class="uk-card-title uk-margin-small-top uk-margin-small-bottom"><?= $e($selected->name) ?></h3>
          <p class="uk-text-muted uk-margin-remove">Published <?= $e($selected->createdAt->format('M j, Y · H:i')) ?>. Earlier issued files continue using their original immutable version.</p>
        </div>
        <?php if ($canArchive): ?>
          <div class="uk-width-auto@m">
            <form method="post" action="<?= $e($adminUrl) ?>documents-archive/" data-kontor-confirm="<?= $e($selected->isArchived() ? 'Restore this document version?' : 'Archive this document version?') ?>">
              <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
              <input type="hidden" name="template_uid" value="<?= $e($selected->uid->toString()) ?>">
              <input type="hidden" name="action" value="<?= $e($selected->isArchived() ? 'restore' : 'archive') ?>">
              <button class="uk-button uk-button-default" type="submit"><i class="fa fa-<?= $selected->isArchived() ? 'undo' : 'archive' ?>"></i> <?= $e($selected->isArchived() ? 'Restore version' : 'Archive version') ?></button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <div class="uk-grid-medium uk-margin-medium-bottom" uk-grid>
      <div class="uk-width-1-1 uk-width-2-3@l">
        <section class="uk-card uk-card-default uk-card-small uk-card-body">
          <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Preview</p>
          <h3 class="uk-card-title uk-margin-small-top uk-margin-small-bottom">Create a sample document</h3>
          <p class="uk-text-muted uk-margin-remove-top">Enter familiar business information to check the layout before using this template in a live workflow.</p>

          <?php if ($canRender): ?>
            <form class="uk-form-stacked" method="post" action="<?= $e($adminUrl) ?>documents-preview/">
              <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
              <input type="hidden" name="template_uid" value="<?= $e($selected->uid->toString()) ?>">
              <?php if ($dynamicPreview): ?>
                <div class="uk-grid-small" uk-grid>
                  <?php foreach ($templateFields as $index => $field): ?>
                    <div class="uk-width-1-1<?= $longField($field) ? '' : ' uk-width-1-2@m' ?>">
                      <input type="hidden" name="field_key[]" value="<?= $e($field) ?>">
                      <label class="uk-form-label" for="document-field-<?= $e((string) $index) ?>"><?= $e($fieldLabel($field)) ?></label>
                      <?php if ($longField($field)): ?>
                        <textarea class="uk-textarea uk-margin-small-top" id="document-field-<?= $e((string) $index) ?>" name="field_value[]" rows="4"></textarea>
                      <?php else: ?>
                        <input class="uk-input uk-margin-small-top" id="document-field-<?= $e((string) $index) ?>" name="field_value[]">
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                  <?php if ($contactsReady): ?>
                    <div class="uk-width-1-1">
                      <label class="uk-form-label" for="document-company">Link to company <span class="uk-text-meta">(optional)</span></label>
                      <select class="uk-select uk-margin-small-top" id="document-company" name="company_uid">
                        <option value="">Keep the PDF with this template</option>
                        <?php foreach ($companies as $company): ?><option value="<?= $e($company->uid->toString()) ?>"><?= $e($company->tradingName ?: $company->legalName) ?></option><?php endforeach; ?>
                      </select>
                      <div class="uk-text-meta uk-margin-small-top">The generated PDF will appear on the company page. An empty Client name field uses the company name.</div>
                    </div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="uk-grid-small" uk-grid>
                  <div class="uk-width-1-1"><label class="uk-form-label" for="document-preview-title">Document title</label><input class="uk-input uk-margin-small-top" id="document-preview-title" name="preview_title" value="Document preview" required></div>
                  <div class="uk-width-1-1 uk-width-1-2@m"><label class="uk-form-label" for="document-preview-customer">Customer</label><input class="uk-input uk-margin-small-top" id="document-preview-customer" name="customer_name" placeholder="Example customer" required></div>
                  <div class="uk-width-1-1 uk-width-1-2@m"><label class="uk-form-label" for="document-preview-amount">Amount</label><input class="uk-input uk-margin-small-top" id="document-preview-amount" name="line_amount" placeholder="1,250.00 USD" required></div>
                  <div class="uk-width-1-1"><label class="uk-form-label" for="document-preview-line">Line description</label><input class="uk-input uk-margin-small-top" id="document-preview-line" name="line_description" placeholder="Consulting services" required></div>
                  <div class="uk-width-1-1"><label class="uk-form-label" for="document-preview-note">Note</label><textarea class="uk-textarea uk-margin-small-top" id="document-preview-note" name="note" rows="3" placeholder="Optional message shown on the document"></textarea></div>
                </div>
              <?php endif; ?>

              <ul class="uk-margin" uk-accordion>
                <li><a class="uk-accordion-title" href>Advanced preview data</a><div class="uk-accordion-content">
                  <label class="uk-form-label" for="document-preview-data">Custom data</label>
                  <textarea class="uk-textarea uk-margin-small-top" id="document-preview-data" name="data_json" rows="7" placeholder="Use JSON only when this template needs fields not available above."></textarea>
                  <div class="uk-text-meta uk-margin-small-top">When supplied, custom data replaces the sample fields above.</div>
                </div></li>
              </ul>

              <button class="uk-button uk-button-primary" type="submit"><i class="fa fa-file-pdf-o"></i> Generate preview and PDF</button>
            </form>
          <?php elseif (!$filesReady): ?>
            <div class="uk-alert-primary" uk-alert><p>PDF previews are unavailable because private file storage is not enabled. You can still manage and publish template versions.</p></div>
          <?php endif; ?>
        </section>
      </div>

      <aside class="uk-width-1-1 uk-width-1-3@l">
        <section class="uk-card uk-card-default uk-card-small uk-card-body">
          <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">How it works</p>
          <h3 class="uk-card-title uk-margin-small-top">Controlled output</h3>
          <ul class="uk-list uk-list-divider uk-margin-remove-bottom">
            <li><strong>1. Preview</strong><span class="uk-display-block uk-text-meta">Check content and layout with sample information.</span></li>
            <li><strong>2. Generate</strong><span class="uk-display-block uk-text-meta">Create an HTML preview and a private PDF.</span></li>
            <li><strong>3. Preserve</strong><span class="uk-display-block uk-text-meta">Keep an immutable snapshot for audit and history.</span></li>
          </ul>
        </section>
      </aside>
    </div>
  <?php endif; ?>

  <?php if ($preview !== null): ?>
    <section class="uk-card uk-card-default uk-card-small uk-card-body uk-margin-medium-bottom">
      <header class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom">
        <div><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Generated output</p><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">Document preview</h3></div>
        <?php if (!empty($preview['fileUid'])): ?><a class="uk-button uk-button-primary" href="<?= $e($adminUrl) ?>files/?id=<?= $e(rawurlencode((string) $preview['fileUid'])) ?>"><i class="fa fa-file-pdf-o"></i> Open saved PDF</a><?php endif; ?>
      </header>
      <p class="uk-text-muted">The PDF is stored privately and linked to this template version. Its issued content will not change when the template is updated.</p>
      <iframe class="kontor-document-preview" sandbox title="Document preview" srcdoc="<?= $e((string) $preview['html']) ?>"></iframe>
    </section>
  <?php endif; ?>

  <section class="uk-card uk-card-default uk-card-small uk-card-body">
    <header class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom">
      <div><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Library</p><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom"><?= $selected === null ? 'Document templates' : 'Other templates' ?></h3></div>
      <?php if ($selected !== null): ?><a class="uk-button uk-button-default uk-button-small" href="<?= $e($adminUrl) ?>documents/"><i class="fa fa-th-list"></i> View full library</a><?php endif; ?>
    </header>
    <?php if ($libraryTemplates !== []): ?>
      <div class="uk-overflow-auto uk-visible@m"><table class="uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small">
        <thead><tr><th>Template</th><th>Version</th><th>Published</th><th>Status</th><th><span class="uk-hidden">Open</span></th></tr></thead>
        <tbody><?php foreach ($libraryTemplates as $template):
          $rowType = $typeLabels[$template->documentType] ?? ucwords(str_replace('_', ' ', $template->documentType));
          $rowLanguage = $languageLabels[$template->language] ?? strtoupper($template->language);
        ?><tr>
          <td><strong><?= $e($template->name) ?></strong><span class="uk-display-block uk-text-meta"><?= $e($rowType) ?> · <?= $e($rowLanguage) ?></span></td>
          <td>Version <?= $e((string) $template->versionNumber) ?></td>
          <td><?= $e($template->createdAt->format('M j, Y')) ?></td>
          <td><span class="uk-label<?= $template->isArchived() ? '' : ' uk-label-success' ?>"><?= $e($template->isArchived() ? 'Archived' : 'Active') ?></span></td>
          <td class="uk-text-right"><a class="uk-icon-link" href="<?= $e($adminUrl) ?>documents/?id=<?= $e(rawurlencode($template->uid->toString())) ?>" uk-icon="arrow-right" aria-label="Open <?= $e($template->name) ?>"></a></td>
        </tr><?php endforeach; ?></tbody>
      </table></div>
      <div class="uk-hidden@m">
        <?php foreach ($libraryTemplates as $template):
          $rowType = $typeLabels[$template->documentType] ?? ucwords(str_replace('_', ' ', $template->documentType));
          $rowLanguage = $languageLabels[$template->language] ?? strtoupper($template->language);
        ?>
          <a class="uk-link-reset uk-display-block uk-padding-small uk-background-muted uk-margin-small-bottom" href="<?= $e($adminUrl) ?>documents/?id=<?= $e(rawurlencode($template->uid->toString())) ?>">
            <span class="uk-flex uk-flex-between uk-flex-middle">
              <strong><?= $e($template->name) ?></strong>
              <span class="uk-label<?= $template->isArchived() ? '' : ' uk-label-success' ?>"><?= $e($template->isArchived() ? 'Archived' : 'Active') ?></span>
            </span>
            <span class="uk-display-block uk-text-meta uk-margin-small-top"><?= $e($rowType) ?> · <?= $e($rowLanguage) ?> · Version <?= $e((string) $template->versionNumber) ?></span>
            <span class="uk-display-block uk-text-meta">Published <?= $e($template->createdAt->format('M j, Y')) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="uk-placeholder uk-text-center"><span uk-icon="icon: file-text; ratio: 1.4"></span><h4 class="uk-margin-small-top uk-margin-small-bottom">No document templates yet</h4><p class="uk-text-muted uk-margin-remove">Create the first reusable template for invoices, quotations or other customer documents.</p></div>
    <?php endif; ?>
  </section>

  <?php if ($editorAvailable): ?>
    <div id="kontor-document-editor" uk-modal>
      <div class="uk-modal-dialog uk-modal-body uk-width-2xlarge">
        <a class="uk-modal-close-default" href="#" role="button" uk-close aria-label="Close"></a>
        <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom"><?= $editing ? 'New controlled version' : 'New template' ?></p>
        <h2 class="uk-modal-title uk-margin-small-top"><?= $editing ? 'Update ' . $e($selected->name) : 'Create document template' ?></h2>
        <p class="uk-text-muted"><?= $editing ? 'Publish changes as a new version so previously issued documents remain unchanged.' : 'Define reusable content for consistent business documents.' ?></p>

        <form class="uk-form-stacked" method="post" action="<?= $e($adminUrl) ?>documents-publish/">
          <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
          <div class="uk-grid-small" uk-grid>
            <div class="uk-width-1-1"><label class="uk-form-label">Template name</label><input class="uk-input uk-margin-small-top" name="name" value="<?= $e($selected?->name ?? '') ?>" placeholder="Standard invoice" required></div>
            <div class="uk-width-1-1 uk-width-1-3@m"><label class="uk-form-label">Document type</label><select class="uk-select uk-margin-small-top" name="document_type" required><?php foreach ($typeLabels as $value => $label): ?><option value="<?= $e($value) ?>"<?= $documentType === $value ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></div>
            <div class="uk-width-1-1 uk-width-1-3@m"><label class="uk-form-label">Language</label><select class="uk-select uk-margin-small-top" name="language" required><?php foreach ($languageLabels as $value => $label): ?><option value="<?= $e($value) ?>"<?= $language === $value ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></div>
            <div class="uk-width-1-1 uk-width-1-3@m"><label class="uk-form-label">Template key</label><input class="uk-input uk-margin-small-top" name="template_key" value="<?= $e($selected?->templateKey ?? '') ?>" placeholder="invoice.standard" required><div class="uk-text-meta uk-margin-small-top">Stable internal reference; keep it unchanged across versions.</div></div>
            <div class="uk-width-1-1"><label class="uk-form-label">Document layout</label><textarea class="uk-textarea uk-margin-small-top" name="body_html" rows="12" required><?= $e($selected?->bodyHtml ?? $defaultBody) ?></textarea><div class="uk-text-meta uk-margin-small-top">Use placeholders such as {{title}}, {{customer.name}}, {{#each lines}}…{{/each}}, and shared sections such as {{> contract.terms}}.</div></div>
          </div>
          <ul class="uk-margin" uk-accordion><li><a class="uk-accordion-title" href>Advanced styling</a><div class="uk-accordion-content"><label class="uk-form-label">Custom CSS</label><textarea class="uk-textarea uk-margin-small-top" name="custom_css" rows="6"><?= $e($selected?->customCss ?? '') ?></textarea></div></li></ul>
          <div class="uk-flex uk-flex-right uk-grid-small" uk-grid><div><button class="uk-button uk-button-default uk-modal-close" type="button">Cancel</button></div><div><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-cloud-upload"></i> <?= $editing ? 'Publish new version' : 'Publish template' ?></button></div></div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

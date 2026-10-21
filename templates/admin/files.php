<?php

/** @var array<int, array<string, mixed>> $files */
/** @var array<int, array<string, mixed>> $allFiles */
/** @var array<string, mixed>|null $selected */
/** @var array<int, array<string, mixed>> $versions */
/** @var array{uid: string, url: string, expiresAt: string}|null $shareResult */
/** @var \Kontor\SDK\DTO\HealthCheckResult $storageHealth */
/** @var bool $canUpload */
/** @var bool $canDownload */
/** @var bool $canShare */
/** @var bool $canDelete */
/** @var string $query */
/** @var string|null $selectedClassification */
/** @var string|null $selectedStatus */
/** @var array<string, string> $entityRoutes */
/** @var string $adminUrl */
/** @var string $csrfName */
/** @var string $csrfValue */
/** @var callable $e */

$formatBytes = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return number_format($bytes / 1024 / 1024, 1) . ' MB';
};
$formatDate = static function (?string $value): string {
    if ($value === null || $value === '') {
        return 'Not yet';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y · H:i');
    } catch (Throwable) {
        return $value;
    }
};
$humanize = static fn (?string $value): string => $value === null || $value === ''
    ? 'Not set'
    : ucwords(str_replace('_', ' ', $value));
$fileIcon = static function (?string $mime): string {
    return match (true) {
        str_contains((string) $mime, 'pdf') => 'file-pdf-o',
        str_starts_with((string) $mime, 'image/') => 'file-image-o',
        str_contains((string) $mime, 'spreadsheet'),
        str_contains((string) $mime, 'excel'),
        str_contains((string) $mime, 'csv') => 'file-excel-o',
        str_contains((string) $mime, 'word'),
        str_contains((string) $mime, 'document') => 'file-word-o',
        str_starts_with((string) $mime, 'text/') => 'file-text-o',
        default => 'file-o',
    };
};
$fileTypeLabel = static function (?string $mime): string {
    return match (true) {
        str_contains((string) $mime, 'pdf') => 'PDF document',
        str_starts_with((string) $mime, 'image/') => 'Image',
        str_contains((string) $mime, 'spreadsheet'),
        str_contains((string) $mime, 'excel'),
        str_contains((string) $mime, 'csv') => 'Spreadsheet',
        str_contains((string) $mime, 'word'),
        str_contains((string) $mime, 'document') => 'Text document',
        str_starts_with((string) $mime, 'text/') => 'Text file',
        default => 'Business file',
    };
};
$entityLink = static function (array $file) use ($entityRoutes, $adminUrl, $e, $humanize): string {
    $type = (string) ($file['entity_type'] ?? '');
    $uid = (string) ($file['entity_uid'] ?? '');
    $label = $type !== '' ? $humanize($type) : 'Unattached';
    if ($type === '' || $uid === '' || !isset($entityRoutes[$type])) {
        return $e($label);
    }

    return '<a href="' . $e($adminUrl . $entityRoutes[$type] . '?id=' . rawurlencode($uid)) . '">'
        . $e($label) . '</a>';
};
$metadata = $selected !== null && is_string($selected['metadata_json'])
    ? json_decode($selected['metadata_json'], true)
    : [];
$activeCount = count(array_filter($allFiles, static fn (array $file): bool => $file['archived_at'] === null));
$archivedCount = count($allFiles) - $activeCount;
$totalBytes = array_sum(array_map(static fn (array $file): int => (int) $file['size_bytes'], $allFiles));
$filtersActive = $query !== '' || $selectedClassification !== null || $selectedStatus !== null;
?>
<div class="ProcessKontor pw-module-workspace kontor-shell">
  <header class="pw-module-head kontor-pagehead">
    <div>
      <p class="kontor-eyebrow"><?= $selected === null ? 'Private document library' : 'File details' ?></p>
      <h2><?= $e($selected !== null ? (string) $selected['original_name'] : 'Files') ?></h2>
      <p><?= $selected === null
          ? 'Store business documents privately, find them quickly and keep every revision connected to its business record.'
          : 'Review access, create a time-limited download and follow this document’s version history.' ?></p>
    </div>
    <div class="pw-module-actions kontor-pagehead__actions">
      <?php if ($selected !== null): ?><a class="uk-button uk-button-default" href="<?= $e($adminUrl) ?>files/"><i class="fa fa-arrow-left"></i> All files</a><?php endif; ?>
      <?php if ($canUpload): ?><button class="uk-button uk-button-primary" type="button" uk-toggle="target: #kontor-file-upload-modal"><i class="fa fa-cloud-upload"></i> Upload file</button><?php endif; ?>
    </div>
  </header>

  <?php if ($storageHealth->status !== 'ok'): ?>
    <div class="uk-alert-danger uk-margin-medium-bottom" uk-alert><p><strong>Private storage needs attention.</strong> <?= $e($storageHealth->message) ?></p></div>
  <?php endif; ?>

  <?php if ($shareResult !== null && $selected !== null && $shareResult['uid'] === $selected['uid']): ?>
    <div class="uk-alert-success uk-margin-medium-bottom" uk-alert>
      <a class="uk-alert-close" uk-close></a>
      <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-grid-small" uk-grid>
        <div><strong>Your secure download is ready.</strong><div class="uk-text-small">This link expires <?= $e($formatDate($shareResult['expiresAt'])) ?>.</div></div>
        <?php if ($canDownload): ?><div><a class="uk-button uk-button-primary" href="<?= $e($shareResult['url']) ?>"><i class="fa fa-download"></i> Download file</a></div><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($selected === null): ?>
    <div class="uk-grid-small uk-child-width-1-1 uk-child-width-1-3@m uk-margin-medium-bottom" uk-grid>
      <div><div class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat"><span class="kontor-stat__icon"><i class="fa fa-folder-open"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $activeCount) ?></strong><span class="kontor-stat__label">Active files</span></span></div></div>
      <div><div class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat"><span class="kontor-stat__icon"><i class="fa fa-hdd-o"></i></span><span><strong class="kontor-stat__value"><?= $e($formatBytes($totalBytes)) ?></strong><span class="kontor-stat__label">Stored securely</span></span></div></div>
      <div><div class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat"><span class="kontor-stat__icon<?= $storageHealth->status === 'ok' ? ' kontor-stat__icon--success' : ' kontor-stat__icon--danger' ?>"><i class="fa fa-shield"></i></span><span><strong class="kontor-stat__value"><?= $e($storageHealth->status === 'ok' ? 'Ready' : 'Check') ?></strong><span class="kontor-stat__label">Private storage</span></span></div></div>
    </div>

    <section class="uk-card uk-card-default uk-card-small uk-card-body">
      <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-grid-small" uk-grid>
        <div>
          <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Document library</p>
          <h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">Stored files</h3>
          <p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom">Open a file to download it, review its business connection or manage versions.</p>
        </div>
        <div class="uk-text-meta"><?= $e((string) count($files)) ?> shown · <?= $e((string) $archivedCount) ?> archived</div>
      </div>

      <form class="uk-form-stacked uk-margin" method="get" action="<?= $e($adminUrl) ?>files/">
        <div class="uk-grid-small uk-flex-bottom" uk-grid>
          <div class="uk-width-1-1 uk-width-expand@m"><label class="uk-form-label" for="files-search">Search files</label><div class="uk-inline uk-width-1-1 uk-margin-small-top"><span class="uk-form-icon" uk-icon="icon: search"></span><input class="uk-input" id="files-search" type="search" name="q" value="<?= $e($query) ?>" placeholder="Search by filename"></div></div>
          <div class="uk-width-1-1 uk-width-1-4@m"><label class="uk-form-label" for="files-classification">Classification</label><select class="uk-select uk-margin-small-top" id="files-classification" name="classification"><option value="">All classifications</option><?php foreach (['general', 'financial', 'confidential', 'restricted'] as $value): ?><option value="<?= $e($value) ?>"<?= $selectedClassification === $value ? ' selected' : '' ?>><?= $e($humanize($value)) ?></option><?php endforeach; ?></select></div>
          <div class="uk-width-1-1 uk-width-1-5@m"><label class="uk-form-label" for="files-status">Status</label><select class="uk-select uk-margin-small-top" id="files-status" name="status"><option value="">All statuses</option><option value="active"<?= $selectedStatus === 'active' ? ' selected' : '' ?>>Active</option><option value="archived"<?= $selectedStatus === 'archived' ? ' selected' : '' ?>>Archived</option></select></div>
          <div class="uk-width-1-1 uk-width-auto@m"><button class="uk-button uk-button-default uk-width-1-1" type="submit">Apply</button></div>
          <?php if ($filtersActive): ?><div class="uk-width-1-1 uk-width-auto@m"><a class="uk-button uk-button-text uk-width-1-1" href="<?= $e($adminUrl) ?>files/">Reset</a></div><?php endif; ?>
        </div>
      </form>

      <?php if ($files !== []): ?>
        <div class="uk-overflow-auto uk-visible@m">
          <table class="uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small">
            <thead><tr><th>File</th><th>Connected record</th><th>Classification</th><th>Version</th><th>Size</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($files as $file): $fileUrl = $adminUrl . 'files/?id=' . rawurlencode((string) $file['uid']); ?><tr>
              <td><a href="<?= $e($fileUrl) ?>"><div class="uk-flex uk-flex-middle uk-grid-small" uk-grid><div><span class="fa fa-<?= $e($fileIcon($file['mime_type'] ?? null)) ?> fa-lg uk-text-muted"></span></div><div><strong><?= $e($file['original_name']) ?></strong><div class="uk-text-meta"><?= $e($fileTypeLabel($file['mime_type'] ?? null)) ?> · <?= $e($humanize((string) ($file['visibility'] ?? 'private'))) ?></div></div></div></a></td>
              <td><?= $entityLink($file) ?></td>
              <td><?= $e($humanize((string) ($file['classification'] ?? ''))) ?></td>
              <td>v<?= $e((string) $file['version_number']) ?></td>
              <td><?= $e($formatBytes((int) $file['size_bytes'])) ?></td>
              <td><span class="uk-label<?= $file['archived_at'] === null ? ' uk-label-success' : '' ?>"><?= $e($file['archived_at'] === null ? 'Active' : 'Archived') ?></span></td>
            </tr><?php endforeach; ?></tbody>
          </table>
        </div>
        <div class="uk-hidden@m">
          <?php foreach ($files as $file): $fileUrl = $adminUrl . 'files/?id=' . rawurlencode((string) $file['uid']); ?>
            <a class="uk-card uk-card-default uk-card-small uk-card-body uk-display-block uk-link-reset uk-margin-small-bottom" href="<?= $e($fileUrl) ?>">
              <div class="uk-flex uk-flex-between uk-flex-top uk-grid-small" uk-grid><div class="uk-width-expand"><div class="uk-flex uk-flex-middle uk-grid-small" uk-grid><div><span class="fa fa-<?= $e($fileIcon($file['mime_type'] ?? null)) ?> fa-lg uk-text-muted"></span></div><div><strong><?= $e($file['original_name']) ?></strong><div class="uk-text-meta"><?= $e($formatBytes((int) $file['size_bytes'])) ?> · Version <?= $e((string) $file['version_number']) ?></div></div></div></div><div><span class="uk-label<?= $file['archived_at'] === null ? ' uk-label-success' : '' ?>"><?= $e($file['archived_at'] === null ? 'Active' : 'Archived') ?></span></div></div>
              <div class="uk-text-small uk-margin-small-top"><?= $e($file['entity_type'] !== null ? $humanize((string) $file['entity_type']) : 'Unattached') ?> · <?= $e($humanize((string) ($file['classification'] ?? ''))) ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="uk-placeholder uk-text-center">
          <span class="fa fa-folder-open-o fa-2x uk-text-muted"></span>
          <h3 class="uk-margin-small-top uk-margin-small-bottom"><?= $filtersActive ? 'No matching files' : 'Your library is empty' ?></h3>
          <p class="uk-text-muted uk-margin-small-top"><?= $filtersActive ? 'Try a broader search or reset the filters.' : 'Upload the first private business document to start the library.' ?></p>
          <?php if ($filtersActive): ?><a class="uk-button uk-button-default" href="<?= $e($adminUrl) ?>files/">Reset filters</a><?php elseif ($canUpload): ?><button class="uk-button uk-button-primary" type="button" uk-toggle="target: #kontor-file-upload-modal"><i class="fa fa-cloud-upload"></i> Upload first file</button><?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php else: ?>
    <section class="uk-card uk-card-default uk-card-small uk-card-body uk-margin-medium-bottom">
      <div class="uk-grid-medium uk-flex-middle" uk-grid>
        <div class="uk-width-auto@m"><span class="fa fa-<?= $e($fileIcon($selected['mime_type'] ?? null)) ?> fa-3x uk-text-muted"></span></div>
        <div class="uk-width-expand@m">
          <div class="uk-flex uk-flex-wrap uk-flex-middle uk-grid-small" uk-grid><div><span class="uk-label<?= $selected['archived_at'] === null ? ' uk-label-success' : '' ?>"><?= $e($selected['archived_at'] === null ? 'Active' : 'Archived') ?></span></div><div class="uk-text-meta">Version <?= $e((string) $selected['version_number']) ?> · <?= $e($humanize((string) $selected['visibility'])) ?></div></div>
          <h3 class="uk-card-title uk-margin-small-top uk-margin-small-bottom"><?= $e($selected['original_name']) ?></h3>
          <p class="uk-text-muted uk-margin-remove">Added <?= $e($formatDate((string) $selected['created_at'])) ?> · <?= $e($formatBytes((int) $selected['size_bytes'])) ?></p>
        </div>
        <div class="uk-width-auto@m"><div class="uk-flex uk-flex-wrap uk-grid-small" uk-grid>
          <?php if ($selected['archived_at'] === null && $canShare): ?><div><form method="post" action="<?= $e($adminUrl) ?>files-share/"><input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>"><input type="hidden" name="file_uid" value="<?= $e($selected['uid']) ?>"><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-download"></i> Create secure download</button></form></div><?php endif; ?>
          <?php if ($canDelete): ?><div><form method="post" action="<?= $e($adminUrl) ?>files-action/" data-kontor-confirm="<?= $e($selected['archived_at'] === null ? 'Archive this file version?' : 'Restore this file version?') ?>"><input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>"><input type="hidden" name="file_uid" value="<?= $e($selected['uid']) ?>"><input type="hidden" name="action" value="<?= $e($selected['archived_at'] === null ? 'archive' : 'restore') ?>"><button class="uk-button uk-button-default" type="submit"><i class="fa fa-<?= $selected['archived_at'] === null ? 'archive' : 'undo' ?>"></i> <?= $e($selected['archived_at'] === null ? 'Archive' : 'Restore') ?></button></form></div><?php endif; ?>
        </div></div>
      </div>
    </section>

    <div class="uk-grid-medium" uk-grid>
      <div class="uk-width-1-1 uk-width-2-3@l">
        <section class="uk-card uk-card-default uk-card-small uk-card-body">
          <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Document information</p><h3 class="uk-card-title uk-margin-small-top">Access and business context</h3>
          <div class="uk-grid-small uk-child-width-1-1 uk-child-width-1-2@m" uk-grid>
            <div><div class="uk-text-meta">Connected record</div><strong><?= $entityLink($selected) ?></strong></div>
            <div><div class="uk-text-meta">Classification</div><strong><?= $e($humanize((string) ($selected['classification'] ?? ''))) ?></strong></div>
            <div><div class="uk-text-meta">Visibility</div><strong><?= $e($humanize((string) ($selected['visibility'] ?? 'private'))) ?></strong></div>
            <div><div class="uk-text-meta">File type</div><strong><?= $e($fileTypeLabel($selected['mime_type'] ?? null)) ?></strong></div>
            <div><div class="uk-text-meta">File size</div><strong><?= $e($formatBytes((int) $selected['size_bytes'])) ?></strong></div>
          </div>
          <ul class="uk-margin-top" uk-accordion><li><a class="uk-accordion-title" href>Technical details</a><div class="uk-accordion-content">
            <dl class="uk-description-list uk-description-list-divider">
              <dt>File identifier</dt><dd><code><?= $e($selected['uid']) ?></code></dd>
              <dt>Checksum</dt><dd><code><?= $e($selected['checksum']) ?></code></dd>
              <dt>Media type</dt><dd><code><?= $e($selected['mime_type'] ?? 'unknown') ?></code></dd>
              <dt>Private storage path</dt><dd><code><?= $e($selected['path']) ?></code></dd>
              <?php if ($selected['entity_uid'] !== null): ?><dt>Connected record identifier</dt><dd><code><?= $e($selected['entity_uid']) ?></code></dd><?php endif; ?>
              <?php if (is_array($metadata) && $metadata !== []): ?><dt>Structured metadata</dt><dd><code><?= $e(json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></code></dd><?php endif; ?>
            </dl>
          </div></li></ul>
        </section>
      </div>
      <div class="uk-width-1-1 uk-width-1-3@l">
        <section class="uk-card uk-card-default uk-card-small uk-card-body">
          <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Revision history</p><h3 class="uk-card-title uk-margin-small-top">Versions</h3>
          <p class="uk-text-muted">Every upload for the same filename and business record is retained as a separate revision.</p>
          <?php if ($versions !== []): ?><ul class="uk-list uk-list-divider"><?php foreach ($versions as $version): ?><li><a class="uk-link-reset uk-display-block" href="<?= $e($adminUrl) ?>files/?id=<?= $e(rawurlencode((string) $version['uid'])) ?>"><div class="uk-flex uk-flex-between uk-flex-middle"><strong>Version <?= $e((string) $version['version_number']) ?></strong><span class="uk-label<?= $version['archived_at'] === null ? ' uk-label-success' : '' ?>"><?= $e($version['archived_at'] === null ? 'Active' : 'Archived') ?></span></div><div class="uk-text-meta uk-margin-small-top"><?= $e($formatDate((string) $version['created_at'])) ?><?= (string) $version['uid'] === (string) $selected['uid'] ? ' · Current view' : '' ?></div></a></li><?php endforeach; ?></ul><?php else: ?><div class="uk-placeholder uk-text-center"><p class="uk-text-muted">No related versions found.</p></div><?php endif; ?>
        </section>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($canUpload): ?>
    <div id="kontor-file-upload-modal" uk-modal>
      <div class="uk-modal-dialog uk-modal-body">
        <a class="uk-modal-close-default" href="#" role="button" uk-close aria-label="Close"></a>
        <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Private storage · 25 MB maximum</p>
        <h2 class="uk-modal-title uk-margin-small-top">Upload a business file</h2>
        <p class="uk-text-muted">Choose who can use the file and how sensitive it is. Files remain private unless Kontor creates a short-lived download.</p>
        <form class="uk-form-stacked" method="post" enctype="multipart/form-data" action="<?= $e($adminUrl) ?>files-upload/">
          <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
          <div class="uk-margin"><label class="uk-form-label" for="file-upload">File</label><input class="uk-input uk-margin-small-top" id="file-upload" type="file" name="file_upload" required><div class="uk-text-meta uk-margin-small-top">Choose a document up to 25 MB.</div></div>
          <div class="uk-grid-small" uk-grid>
            <div class="uk-width-1-1 uk-width-1-2@m"><label class="uk-form-label" for="file-visibility">Who can use it?</label><select class="uk-select uk-margin-small-top" id="file-visibility" name="visibility" required><option value="private">Linked record only</option><option value="internal">People in this organization</option></select><div class="uk-text-meta uk-margin-small-top">Private is the safest default for customer documents.</div></div>
            <div class="uk-width-1-1 uk-width-1-2@m"><label class="uk-form-label" for="file-classification">Sensitivity</label><select class="uk-select uk-margin-small-top" id="file-classification" name="classification" required><option value="general">General business file</option><option value="financial">Financial</option><option value="confidential">Confidential</option><option value="restricted">Restricted</option></select><div class="uk-text-meta uk-margin-small-top">Use Restricted for the most sensitive material.</div></div>
          </div>
          <ul class="uk-margin" uk-accordion><li><a class="uk-accordion-title" href>Connect to an advanced record</a><div class="uk-accordion-content">
            <p class="uk-text-muted">Most components connect files automatically. Use this only when you know the record type and identifier.</p>
            <div class="uk-grid-small" uk-grid><div class="uk-width-1-1 uk-width-1-2@m"><label class="uk-form-label" for="file-entity-type">Record type</label><input class="uk-input uk-margin-small-top" id="file-entity-type" name="entity_type" maxlength="50" placeholder="For example, project"></div><div class="uk-width-1-1 uk-width-1-2@m"><label class="uk-form-label" for="file-entity-uid">Record identifier</label><input class="uk-input uk-margin-small-top" id="file-entity-uid" name="entity_uid" maxlength="26"></div></div>
            <label class="uk-form-label uk-display-block uk-margin" for="file-metadata">Additional structured metadata</label><textarea class="uk-textarea uk-margin-small-top" id="file-metadata" name="metadata_json" rows="4" placeholder="Optional JSON for integrations"></textarea>
          </div></li></ul>
          <div class="uk-flex uk-flex-right uk-flex-middle uk-grid-small" uk-grid><div><button class="uk-button uk-button-default uk-modal-close" type="button">Cancel</button></div><div><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-cloud-upload"></i> Upload securely</button></div></div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

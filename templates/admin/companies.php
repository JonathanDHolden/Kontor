<?php

/** @var array $companies */
/** @var string $query */
/** @var string $selectedStatus */
/** @var bool $showArchived */
/** @var int $page */
/** @var int $totalPages */
/** @var int $totalRecords */
/** @var array<string, string> $companyFields */
/** @var string $adminUrl */
/** @var string $csrfName */
/** @var string $csrfValue */
/** @var callable $e */

$pageUrl = static function (int $targetPage) use ($query, $selectedStatus, $showArchived): string {
    return './?' . http_build_query(array_filter([
        'q' => $query,
        'status' => $selectedStatus,
        'archived' => $showArchived ? 1 : '',
        'page' => $targetPage,
    ], static fn (string|int $value): bool => $value !== ''));
};
$viewUrl = './?' . http_build_query(array_filter([
    'q' => $query,
    'archived' => $showArchived ? '' : 1,
], static fn (string|int $value): bool => $value !== ''));
$filterParameters = array_filter([
    'q' => $query,
    'status' => $selectedStatus,
], static fn (string $value): bool => $value !== '');
$statusUrl = static fn (string $status): string => './?' . http_build_query([
    ...$filterParameters,
    'status' => $status,
]);
$clearFiltersUrl = $showArchived ? './?archived=1' : './';
$hasFilters = $query !== '' || $selectedStatus !== '';
?>
<div class="ProcessKontor pw-module-workspace kontor-shell">
  <header class="pw-module-head kontor-pagehead">
    <div>
      <p class="kontor-eyebrow">Commercial directory</p>
      <h2>Companies</h2>
      <p>Customers, partners and the organizations behind your work.</p>
    </div>
    <div class="pw-module-actions kontor-pagehead__actions">
      <a class="uk-button uk-button-secondary kontor-button kontor-button--ghost" href="<?= $e($adminUrl) ?>import/?entity=company">
        <i class="fa fa-upload"></i> Import
      </a>
      <a class="uk-button uk-button-secondary kontor-button kontor-button--ghost" href="<?= $e($adminUrl) ?>export/?entity=company&amp;format=csv">
        <i class="fa fa-download"></i> CSV
      </a>
      <a class="uk-button uk-button-secondary kontor-button kontor-button--ghost" href="<?= $e($adminUrl) ?>export/?entity=company&amp;format=json">
        JSON
      </a>
      <a class="uk-button uk-button-primary kontor-button" href="<?= $e($adminUrl) ?>company/">
        <i class="fa fa-plus"></i> New company
      </a>
    </div>
  </header>

  <form class="kontor-toolbar" method="get" action="./">
    <?php if ($showArchived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
    <label class="kontor-searchfield">
      <i class="fa fa-search"></i>
      <input name="q" type="search" value="<?= $e($query) ?>" placeholder="Search company, email or registration">
    </label>
    <?php if (!$showArchived): ?>
      <select name="status" aria-label="Company status">
        <option value="">All statuses</option>
        <option value="active"<?= $selectedStatus === 'active' ? ' selected' : '' ?>>Active</option>
        <option value="inactive"<?= $selectedStatus === 'inactive' ? ' selected' : '' ?>>Inactive</option>
      </select>
    <?php endif; ?>
    <button class="uk-button uk-button-primary kontor-button" type="submit">Filter</button>
    <?php if ($hasFilters): ?>
      <a class="kontor-viewtoggle" href="<?= $e($clearFiltersUrl) ?>">
        <i class="fa fa-times"></i> Clear filters
      </a>
    <?php endif; ?>
    <a class="kontor-viewtoggle" href="<?= $e($viewUrl) ?>">
      <i class="fa fa-<?= $showArchived ? 'building' : 'archive' ?>"></i>
      <?= $showArchived ? 'Active companies' : 'Archive' ?>
    </a>
    <span class="kontor-secondary"><?= $e($totalRecords) ?> total · <?= $e(count($companies)) ?> shown</span>
  </form>

  <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card pw-table-panel uk-overflow-auto kontor-tablewrap kontor-directorytable">
    <?php if ($companies): ?>
      <table class="uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small kontor-table">
        <thead>
          <tr>
            <th>Company</th>
            <th>Contact</th>
            <?php foreach ($companyFields as $label): ?>
              <th><?= $e($label) ?></th>
            <?php endforeach; ?>
            <th>Registration</th>
            <th>Status</th>
            <th><span class="kontor-visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($companies as $company): ?>
            <tr>
              <td>
                <div class="kontor-identity">
                  <span class="kontor-avatar"><?= $e(mb_substr($company->legalName, 0, 2)) ?></span>
                  <span>
                    <a href="<?= $e($adminUrl) ?>company/?id=<?= $e(rawurlencode($company->uid->toString())) ?>">
                      <?= $e($company->legalName) ?>
                    </a>
                    <span class="kontor-secondary"><?= $e($company->tradingName ?: $company->website ?: 'Company') ?></span>
                  </span>
                </div>
              </td>
              <td>
                <?= $e($company->email ?: '—') ?>
                <?php if ($company->phone): ?><span class="kontor-secondary"><?= $e($company->phone) ?></span><?php endif; ?>
              </td>
              <?php foreach ($companyFields as $key => $label): ?>
                <?php $fieldValue = $company->metadata['fields'][$key] ?? ''; ?>
                <td class="kontor-companyfield"><?= is_string($fieldValue) && $fieldValue !== '' ? $e($fieldValue) : '—' ?></td>
              <?php endforeach; ?>
              <td><?= $e($company->registrationNumber ?: $company->vatNumber ?: '—') ?></td>
              <td>
                <?php if ($showArchived): ?>
                  <span class="uk-label kontor-pill kontor-pill--inactive">archived</span>
                <?php else: ?>
                  <a
                    class="uk-label kontor-pill<?= $company->status === 'active' ? '' : ' kontor-pill--inactive' ?>"
                    href="<?= $e($statusUrl($company->status)) ?>"
                    <?= $selectedStatus === $company->status ? 'aria-current="page"' : '' ?>
                  ><?= $e($company->status) ?></a>
                <?php endif; ?>
              </td>
              <td class="kontor-rowaction">
                <form method="post" action="<?= $e($adminUrl) ?>company-status/">
                  <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
                  <input type="hidden" name="id" value="<?= $e($company->uid->toString()) ?>">
                  <input type="hidden" name="action" value="<?= $showArchived ? 'restore' : 'archive' ?>">
                  <button type="submit" title="<?= $showArchived ? 'Restore company' : 'Archive company' ?>">
                    <i class="fa fa-<?= $showArchived ? 'undo' : 'archive' ?>"></i>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="pw-empty-state uk-placeholder uk-text-center kontor-empty">
        <i class="fa fa-building"></i>
        <h3><?= $query !== '' ? 'No matching companies' : ($showArchived ? 'The archive is empty' : 'No companies yet') ?></h3>
        <p><?= $query !== '' ? 'Try a broader search.' : ($showArchived ? 'Archived companies will appear here.' : 'Create your first customer or partner company.') ?></p>
      </div>
    <?php endif; ?>
  </section>
  <?php if ($totalPages > 1): ?>
    <nav class="kontor-pagination" aria-label="Company pages">
      <span>Page <?= $e($page) ?> of <?= $e($totalPages) ?></span>
      <div>
        <?php if ($page > 1): ?>
          <a class="uk-button uk-button-secondary kontor-button kontor-button--ghost" href="<?= $e($pageUrl($page - 1)) ?>">
            <i class="fa fa-chevron-left"></i> Previous
          </a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a class="uk-button uk-button-secondary kontor-button kontor-button--ghost" href="<?= $e($pageUrl($page + 1)) ?>">
            Next <i class="fa fa-chevron-right"></i>
          </a>
        <?php endif; ?>
      </div>
    </nav>
  <?php endif; ?>
</div>

<?php

/** @var \Kontor\Contacts\Domain\Contact[] $contacts */
/** @var string $query */
/** @var string $selectedStatus */
/** @var bool $showArchived */
/** @var int $page */
/** @var int $totalPages */
/** @var int $totalRecords */
/** @var array{all: int, active: int, inactive: int, linkedin: int, archived: int} $contactCounts */
/** @var array<string, string> $companyNames */
/** @var bool $withLinkedIn */
/** @var string $adminUrl */
/** @var string $csrfName */
/** @var string $csrfValue */
/** @var callable $e */

$pageUrl = static function (int $targetPage) use ($query, $selectedStatus, $showArchived, $withLinkedIn): string {
    $parameters = http_build_query(array_filter([
        'q' => $query,
        'status' => $selectedStatus,
        'archived' => $showArchived ? 1 : '',
        'linkedin' => $withLinkedIn ? 1 : '',
        'page' => $targetPage,
    ], static fn (string|int $value): bool => $value !== ''));

    return $parameters === '' ? './' : './?' . $parameters;
};
$clearSearchParameters = http_build_query(array_filter([
    'status' => $selectedStatus,
    'archived' => $showArchived ? 1 : '',
    'linkedin' => $withLinkedIn ? 1 : '',
], static fn (string|int $value): bool => $value !== ''));
$clearSearchUrl = $clearSearchParameters === '' ? './' : './?' . $clearSearchParameters;
$tabUrl = static function (string $status = '', bool $archived = false, bool $linkedin = false) use ($query): string {
    $parameters = http_build_query(array_filter([
        'q' => $query,
        'status' => $status,
        'archived' => $archived ? 1 : '',
        'linkedin' => $linkedin ? 1 : '',
    ], static fn (string|int $value): bool => $value !== ''));

    return $parameters === '' ? './' : './?' . $parameters;
};
$directoryTitle = $showArchived
    ? 'Archived contacts'
    : ($withLinkedIn ? 'Contacts on LinkedIn' : ($selectedStatus !== '' ? ucfirst($selectedStatus) . ' contacts' : 'Contact directory'));
?>
<div class="ProcessKontor pw-module-workspace kontor-shell">
  <header class="uk-grid-small uk-flex-middle uk-margin-medium-bottom kontor-contact-listhead" uk-grid>
    <div class="uk-width-1-1 uk-width-expand@m">
      <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Customer directory</p>
      <p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom">Find people, maintain communication details and open their connected customer history.</p>
    </div>
    <div class="uk-width-1-1 uk-width-auto@m">
      <div class="uk-flex uk-flex-wrap uk-grid-small kontor-directory-actions" uk-grid>
        <div>
          <a class="uk-button uk-button-default kontor-button" href="<?= $e($adminUrl) ?>import/?entity=contact">
            <i class="fa fa-upload"></i> Import
          </a>
        </div>
        <div class="uk-inline">
          <button class="uk-button uk-button-default kontor-button" type="button">
            <i class="fa fa-download"></i> Export <span uk-icon="icon: chevron-down"></span>
          </button>
          <div uk-dropdown="mode: click; pos: bottom-right">
            <ul class="uk-nav uk-dropdown-nav">
              <li class="uk-nav-header">Export contacts</li>
              <li><a href="<?= $e($adminUrl) ?>export/?entity=contact&amp;format=csv">CSV spreadsheet</a></li>
              <li><a href="<?= $e($adminUrl) ?>export/?entity=contact&amp;format=json">JSON data</a></li>
            </ul>
          </div>
        </div>
        <div>
          <a class="uk-button uk-button-primary kontor-button" href="<?= $e($adminUrl) ?>contact/">
            <i class="fa fa-plus"></i> New contact
          </a>
        </div>
      </div>
    </div>
  </header>

  <nav aria-label="Contact views">
    <ul class="uk-tab uk-margin-remove-bottom">
      <li<?= !$showArchived && !$withLinkedIn && $selectedStatus === '' ? ' class="uk-active"' : '' ?>>
        <a href="<?= $e($tabUrl()) ?>">All <span class="uk-badge"><?= $e($contactCounts['all']) ?></span></a>
      </li>
      <li<?= !$showArchived && !$withLinkedIn && $selectedStatus === 'active' ? ' class="uk-active"' : '' ?>>
        <a href="<?= $e($tabUrl('active')) ?>">Active <span class="uk-badge"><?= $e($contactCounts['active']) ?></span></a>
      </li>
      <li<?= !$showArchived && !$withLinkedIn && $selectedStatus === 'inactive' ? ' class="uk-active"' : '' ?>>
        <a href="<?= $e($tabUrl('inactive')) ?>">Inactive <span class="uk-badge"><?= $e($contactCounts['inactive']) ?></span></a>
      </li>
      <li<?= !$showArchived && $withLinkedIn ? ' class="uk-active"' : '' ?>>
        <a href="<?= $e($tabUrl('', false, true)) ?>">On LinkedIn <span class="uk-badge"><?= $e($contactCounts['linkedin']) ?></span></a>
      </li>
      <li<?= $showArchived ? ' class="uk-active"' : '' ?>>
        <a href="<?= $e($tabUrl('', true)) ?>">Archive <span class="uk-badge"><?= $e($contactCounts['archived']) ?></span></a>
      </li>
    </ul>
  </nav>

  <section class="uk-card uk-card-default uk-card-small uk-card-body">
    <form class="uk-form-stacked uk-margin-bottom" method="get" action="./" role="search">
      <?php if ($showArchived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
      <?php if (!$showArchived && $selectedStatus !== ''): ?><input type="hidden" name="status" value="<?= $e($selectedStatus) ?>"><?php endif; ?>
      <?php if (!$showArchived && $withLinkedIn): ?><input type="hidden" name="linkedin" value="1"><?php endif; ?>
      <div class="uk-grid-small uk-flex-middle" uk-grid>
        <div class="uk-width-1-1 uk-width-expand@s">
          <div class="uk-search uk-search-default uk-width-1-1">
            <span uk-search-icon></span>
            <input
              class="uk-search-input"
              name="q"
              type="search"
              value="<?= $e($query) ?>"
              placeholder="Search name, email, phone or role…"
              aria-label="Search contacts"
            >
          </div>
        </div>
        <div class="uk-width-auto@s">
          <button class="uk-button uk-button-primary" type="submit">Search</button>
        </div>
        <?php if ($query !== ''): ?>
          <div class="uk-width-auto@s">
            <a class="uk-button uk-button-default" href="<?= $e($clearSearchUrl) ?>">Clear</a>
          </div>
        <?php endif; ?>
      </div>
    </form>

    <header class="uk-flex uk-flex-between uk-flex-middle uk-margin-small-bottom">
      <div>
        <h3 class="uk-h4 uk-margin-remove"><?= $e($directoryTitle) ?></h3>
        <p class="uk-text-meta uk-margin-small-top uk-margin-remove-bottom">
          <?= $e($totalRecords) ?> result<?= $totalRecords === 1 ? '' : 's' ?><?= $query !== '' ? ' for “' . $e($query) . '”' : '' ?>
        </p>
      </div>
      <?php if ($totalPages > 1): ?><span class="uk-text-meta">Page <?= $e($page) ?> of <?= $e($totalPages) ?></span><?php endif; ?>
    </header>

    <?php if ($contacts): ?>
      <table class="uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small uk-table-responsive kontor-table kontor-directorytable">
        <thead>
          <tr>
            <th>Contact</th>
            <th>Work</th>
            <th>Contact details</th>
            <th>Status</th>
            <th><span class="kontor-visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($contacts as $contact): ?>
            <tr>
              <td>
                <div class="kontor-identity">
                  <span class="kontor-avatar"><?= $e(mb_substr($contact->displayName, 0, 2)) ?></span>
                  <span class="kontor-identity__body">
                    <a href="<?= $e($adminUrl) ?>contact/?id=<?= $e(rawurlencode($contact->uid->toString())) ?>">
                      <strong><?= $e($contact->displayName) ?></strong>
                    </a>
                    <span class="uk-text-meta"><?= $e($contact->type === 'person' ? 'Person' : ucfirst($contact->type)) ?></span>
                  </span>
                </div>
              </td>
              <td data-label="Work">
                <?= $e($contact->jobTitle ?: 'No role specified') ?>
                <?php $companyName = $companyNames[$contact->uid->toString()] ?? ''; ?>
                <?php if ($companyName !== ''): ?><span class="uk-text-meta uk-display-block"><?= $e($companyName) ?></span><?php endif; ?>
              </td>
              <td data-label="Contact details">
                <div class="uk-text-small">
                  <div><i class="fa fa-envelope uk-text-muted uk-margin-small-right"></i><?= $e($contact->email ?: 'No email') ?></div>
                  <div class="uk-margin-small-top"><i class="fa fa-phone uk-text-muted uk-margin-small-right"></i><?= $e($contact->mobile ?: $contact->phone ?: 'No phone') ?></div>
                  <?php $linkedin = (string) ($contact->metadata['linkedin'] ?? ''); ?>
                  <?php if ($linkedin !== ''): ?>
                    <div class="uk-margin-small-top"><i class="fa fa-linkedin uk-text-muted uk-margin-small-right"></i><a href="<?= $e($linkedin) ?>" target="_blank" rel="noopener noreferrer">LinkedIn profile<span class="kontor-visually-hidden"> of <?= $e($contact->displayName) ?></span></a></div>
                  <?php endif; ?>
                </div>
              </td>
              <td data-label="Status">
                <?php if ($showArchived): ?>
                  <span class="uk-label">Archived</span>
                <?php else: ?>
                  <span class="uk-label<?= $contact->status === 'active' ? ' uk-label-success' : '' ?>"><?= $e(ucfirst($contact->status)) ?></span>
                <?php endif; ?>
              </td>
              <td class="kontor-rowaction">
                <div class="uk-inline">
                  <button class="uk-button uk-button-default uk-button-small" type="button" aria-label="Actions for <?= $e($contact->displayName) ?>">
                    <i class="fa fa-ellipsis-v"></i>
                  </button>
                  <div uk-dropdown="mode: click; pos: bottom-right">
                    <ul class="uk-nav uk-dropdown-nav">
                      <li>
                        <a href="<?= $e($adminUrl) ?>contact/?id=<?= $e(rawurlencode($contact->uid->toString())) ?>">
                          <i class="fa fa-pencil"></i> Open contact
                        </a>
                      </li>
                      <li class="uk-nav-divider"></li>
                      <li>
                        <form
                          method="post"
                          action="<?= $e($adminUrl) ?>contact-status/"
                          data-kontor-confirm="<?= $e(($showArchived ? 'Restore ' : 'Archive ') . $contact->displayName . '?') ?>"
                        >
                          <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
                          <input type="hidden" name="id" value="<?= $e($contact->uid->toString()) ?>">
                          <input type="hidden" name="action" value="<?= $showArchived ? 'restore' : 'archive' ?>">
                          <button class="uk-button uk-button-text" type="submit">
                            <i class="fa fa-<?= $showArchived ? 'undo' : 'archive' ?>"></i>
                            <?= $showArchived ? 'Restore' : 'Archive' ?>
                          </button>
                        </form>
                      </li>
                    </ul>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="uk-placeholder uk-text-center">
        <span uk-icon="icon: users; ratio: 1.5"></span>
        <h3 class="uk-margin-small-top uk-margin-small-bottom"><?= $query !== '' ? 'No matching contacts' : ($showArchived ? 'The archive is empty' : 'Your contact list is empty') ?></h3>
        <p class="uk-text-muted uk-margin-remove-top"><?= $query !== '' ? 'Try a broader search or clear the current query.' : ($showArchived ? 'Archived contacts will appear here.' : 'Create the first person in your directory.') ?></p>
        <?php if ($query !== ''): ?>
          <a class="uk-button uk-button-default" href="<?= $e($clearSearchUrl) ?>">Clear search</a>
        <?php elseif (!$showArchived): ?>
          <a class="uk-button uk-button-primary" href="<?= $e($adminUrl) ?>contact/"><i class="fa fa-plus"></i> New contact</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($totalPages > 1): ?>
    <nav class="kontor-pagination" aria-label="Contact pages">
      <span>Page <?= $e($page) ?> of <?= $e($totalPages) ?></span>
      <div>
        <?php if ($page > 1): ?>
          <a class="uk-button uk-button-default" href="<?= $e($pageUrl($page - 1)) ?>"><i class="fa fa-chevron-left"></i> Previous</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a class="uk-button uk-button-default" href="<?= $e($pageUrl($page + 1)) ?>">Next <i class="fa fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
    </nav>
  <?php endif; ?>
</div>

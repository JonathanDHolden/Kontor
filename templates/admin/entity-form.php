<?php

/** @var \ProcessWire\InputfieldForm $form */
/** @var string $backUrl */
/** @var string $backLabel */
/** @var string $eyebrow */
/** @var string $title */
/** @var string $description */
/** @var object|null $entity */
/** @var string $entityType */
/** @var array $relationships */
/** @var array $availableCompanies */
/** @var array $addresses */
/** @var array $duplicates */
/** @var array $tags */
/** @var array{available: bool, actions: array, leads: array, deals: array, tasks: array} $workspace */
/** @var string $adminUrl */
/** @var string $csrfName */
/** @var string $csrfValue */
/** @var callable $e */
$aiReady = $aiReady ?? false;
$aiSummary = $aiSummary ?? null;
$companyFiles = $companyFiles ?? [];
$canViewCompanyFiles = $canViewCompanyFiles ?? false;
if ($entityType === 'contact') {
    require __DIR__ . '/contact-form.php';
    return;
}
?>
<div class="ProcessKontor pw-module-workspace kontor-shell">
  <header class="kontor-formhead">
    <a class="kontor-formhead__back" href="<?= $e($backUrl) ?>">
      <i class="fa fa-arrow-left"></i> <?= $e($backLabel) ?>
    </a>
    <p class="kontor-eyebrow"><?= $e($eyebrow) ?></p>
    <h2><?= $e($title) ?></h2>
    <p><?= $e($description) ?></p>
  </header>
  <?php if ($duplicates): ?>
    <aside class="uk-alert uk-alert-warning kontor-warning" role="alert">
      <i class="fa fa-exclamation-triangle"></i>
      <div>
        <strong>Possible duplicate contact</strong>
        <p>
          The email or phone already belongs to
          <?php foreach ($duplicates as $index => $duplicate): ?>
            <?= $index > 0 ? ', ' : '' ?><a href="<?= $e($adminUrl) ?>contact/?id=<?= $e(rawurlencode($duplicate->uid->toString())) ?>"><?= $e($duplicate->displayName) ?></a>
          <?php endforeach; ?>.
          Review the existing record or confirm creation below.
        </p>
      </div>
    </aside>
  <?php endif; ?>
  <?= $form->render() ?>

  <?php if ($entity !== null): ?>
    <?php if ($entityType === 'company' && $canViewCompanyFiles): ?>
      <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card">
        <div class="kontor-sectionhead">
          <div><p class="kontor-eyebrow">Documents</p><h3>Company files</h3></div>
          <span class="kontor-secondary"><?= $e(count($companyFiles)) ?> linked</span>
        </div>
        <?php if ($companyFiles !== []): ?>
          <ul class="uk-list uk-list-divider uk-margin-remove-bottom">
            <?php foreach ($companyFiles as $file): ?>
              <li class="uk-flex uk-flex-between uk-flex-middle uk-grid-small" uk-grid>
                <div class="uk-width-expand"><strong><?= $e((string) $file['original_name']) ?></strong><span class="uk-display-block uk-text-meta">PDF · <?= $e(number_format((int) $file['size_bytes'] / 1024, 1)) ?> KB</span></div>
                <div class="uk-width-auto"><a class="uk-button uk-button-default uk-button-small" href="<?= $e($adminUrl) ?>files/?id=<?= $e(rawurlencode((string) $file['uid'])) ?>">Open file</a></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="kontor-secondary uk-margin-remove-bottom">No documents are linked to this company yet.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>
    <?php if ($workspace['available']): ?>
      <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card">
        <header class="kontor-sectionhead uk-flex-wrap uk-grid-small" uk-grid>
          <div class="uk-width-expand@m">
            <p class="kontor-eyebrow">Connected workspace</p>
            <h3>Customer work</h3>
            <p class="uk-text-meta uk-margin-small-top">Leads, deals and tasks connected to this <?= $e($entityType) ?>.</p>
          </div>
          <?php if ($workspace['actions'] !== []): ?>
            <div class="uk-width-auto@m">
              <div class="uk-flex uk-flex-wrap uk-flex-right@m uk-grid-small" uk-grid>
                <?php foreach ($workspace['actions'] as $action): ?>
                  <div><a class="uk-button <?= $action['primary'] ? 'uk-button-primary' : 'uk-button-default' ?>" href="<?= $e($adminUrl . $action['route']) ?>"><i class="fa fa-<?= $e($action['icon']) ?>"></i> <?= $e($action['label']) ?></a></div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </header>

        <?php if ($workspace['leads'] === [] && $workspace['deals'] === [] && $workspace['tasks'] === []): ?>
          <div class="uk-placeholder uk-text-center uk-margin-top">
            <p class="uk-margin-remove">No customer work is connected yet. Start with one of the available actions above.</p>
          </div>
        <?php else: ?>
          <div class="uk-child-width-1-3@m uk-grid-small uk-grid-match uk-margin-top" uk-grid>
            <?php foreach ([
              ['title' => 'Leads', 'route' => 'crm-lead/', 'records' => $workspace['leads']],
              ['title' => 'Deals', 'route' => 'crm-deal/', 'records' => $workspace['deals']],
              ['title' => 'Tasks', 'route' => 'task/', 'records' => $workspace['tasks']],
            ] as $group): ?>
              <?php if ($group['records'] !== []): ?>
                <div>
                  <div>
                    <h4 class="uk-margin-remove-top"><?= $e($group['title']) ?></h4>
                    <ul class="uk-list uk-list-divider uk-margin-remove-bottom">
                      <?php foreach ($group['records'] as $record): ?>
                        <li>
                          <a class="uk-link-text" href="<?= $e($adminUrl . $group['route']) ?>?id=<?= $e(rawurlencode($record->uid->toString())) ?>"><strong><?= $e($record->title) ?></strong></a>
                          <div class="uk-text-meta uk-text-capitalize"><?= $e(str_replace('_', ' ', $record->status)) ?></div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($entityType === 'contact' && $aiReady): ?>
      <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card">
        <div class="kontor-sectionhead">
          <div>
            <p class="kontor-eyebrow">AI · Customer context</p>
            <h3>Contact brief</h3>
          </div>
          <?php if ($aiSummary !== null): ?><span class="uk-label kontor-pill"><?= $e($aiSummary['simulated'] ? 'local preview' : 'provider') ?></span><?php endif; ?>
        </div>
        <?php if ($aiSummary !== null): ?>
          <p><?= $e($aiSummary['summary']) ?></p>
          <p class="kontor-secondary"><?= $e((string) $aiSummary['characters']) ?> source characters · generated on demand</p>
        <?php else: ?>
          <p class="kontor-secondary">Build a concise brief from this contact’s identity, notes, tags, and company relationships.</p>
        <?php endif; ?>
        <form method="post" action="<?= $e($adminUrl) ?>contact-ai-summary/">
          <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
          <input type="hidden" name="contact_uid" value="<?= $e($entity->uid->toString()) ?>">
          <label class="kontor-check"><input type="checkbox" name="simulate" value="1" checked> <span>Use local preview</span></label>
          <button class="uk-button uk-button-secondary kontor-button kontor-button--ghost" type="submit"><i class="fa fa-magic"></i> Generate contact brief</button>
        </form>
      </section>
    <?php endif; ?>

    <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card kontor-tags">
      <div class="kontor-sectionhead">
        <div>
          <p class="kontor-eyebrow">Classification</p>
          <h3>Tags</h3>
        </div>
        <div class="kontor-taglist">
          <?php foreach ($tags as $tag): ?>
            <span class="kontor-tag"><?= $e($tag) ?></span>
          <?php endforeach; ?>
          <?php if (!$tags): ?><span class="kontor-secondary">No tags</span><?php endif; ?>
        </div>
      </div>
      <form class="kontor-tagform" method="post" action="<?= $e($adminUrl) ?>tags/">
        <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
        <input type="hidden" name="owner_type" value="<?= $e($entityType) ?>">
        <input type="hidden" name="owner_uid" value="<?= $e($entity->uid->toString()) ?>">
        <input type="text" name="tags" value="<?= $e(implode(', ', $tags)) ?>" placeholder="customer, partner, vip">
        <button class="uk-button uk-button-secondary kontor-button kontor-button--ghost" type="submit">
          <i class="fa fa-tags"></i> Update tags
        </button>
      </form>
    </section>

    <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card kontor-relations">
      <div class="kontor-sectionhead">
        <div>
          <p class="kontor-eyebrow"><?= $entityType === 'contact' ? 'Affiliations' : 'People' ?></p>
          <h3><?= $entityType === 'contact' ? 'Company relationships' : 'Company contacts' ?></h3>
        </div>
        <span class="kontor-secondary"><?= $e(count($relationships)) ?> linked</span>
      </div>

      <?php if ($relationships): ?>
        <div class="kontor-relationlist">
          <?php foreach ($relationships as $relationship): ?>
            <?php $membership = $relationship['membership']; $related = $relationship['entity']; ?>
            <article class="kontor-relation">
              <span class="kontor-avatar"><?= $e(mb_substr($entityType === 'contact' ? $related->legalName : $related->displayName, 0, 2)) ?></span>
              <div class="kontor-relation__body">
                <a href="<?= $e($adminUrl) ?><?= $entityType === 'contact' ? 'company' : 'contact' ?>/?id=<?= $e(rawurlencode($related->uid->toString())) ?>">
                  <?= $e($entityType === 'contact' ? $related->legalName : $related->displayName) ?>
                </a>
                <?php $relatedLinkedIn = $entityType === 'company' ? (string) ($related->metadata['linkedin'] ?? '') : ''; ?>
                <?php if ($relatedLinkedIn !== ''): ?>
                  <a class="uk-margin-small-left" href="<?= $e($relatedLinkedIn) ?>" target="_blank" rel="noopener noreferrer" title="LinkedIn profile of <?= $e($related->displayName) ?>"><i class="fa fa-linkedin"></i><span class="kontor-visually-hidden">LinkedIn profile</span></a>
                <?php endif; ?>
                <span class="kontor-secondary">
                  <?= $e($membership->role ?: 'Member') ?>
                  <?= $membership->department ? ' · ' . $e($membership->department) : '' ?>
                </span>
              </div>
              <span class="uk-label kontor-pill<?= $membership->endedAt ? ' kontor-pill--inactive' : '' ?>">
                <?= $membership->endedAt ? 'ended ' . $e($membership->endedAt->format('M Y')) : 'current' ?>
              </span>
              <?php if ($entityType === 'contact' && $membership->endedAt === null): ?>
                <form class="kontor-inline-action" method="post" action="<?= $e($adminUrl) ?>relationship/">
                  <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
                  <input type="hidden" name="action" value="end">
                  <input type="hidden" name="contact_id" value="<?= $e($entity->uid->toString()) ?>">
                  <input type="hidden" name="company_id" value="<?= $e($related->uid->toString()) ?>">
                  <button type="submit" title="End relationship"><i class="fa fa-unlink"></i></button>
                </form>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="kontor-relations__empty">
          <?= $entityType === 'contact' ? 'This contact is not linked to a company yet.' : 'No contacts are linked to this company yet.' ?>
        </p>
      <?php endif; ?>

      <?php if ($entityType === 'contact' && $availableCompanies): ?>
        <form class="kontor-relationform" method="post" action="<?= $e($adminUrl) ?>relationship/">
          <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="contact_id" value="<?= $e($entity->uid->toString()) ?>">
          <label>
            <span>Company</span>
            <select name="company_id" required>
              <option value="">Choose a company</option>
              <?php foreach ($availableCompanies as $company): ?>
                <option value="<?= $e($company->uid->toString()) ?>"><?= $e($company->legalName) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            <span>Role</span>
            <input type="text" name="role" placeholder="e.g. Decision maker">
          </label>
          <label>
            <span>Department</span>
            <input type="text" name="department" placeholder="e.g. Operations">
          </label>
          <button class="uk-button uk-button-secondary kontor-button kontor-button--ghost" type="submit">
            <i class="fa fa-link"></i> Link company
          </button>
        </form>
      <?php elseif ($entityType === 'contact'): ?>
        <p class="kontor-relations__empty">
          <a href="<?= $e($adminUrl) ?>company/">Create a company</a> before adding an affiliation.
        </p>
      <?php endif; ?>
    </section>

    <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card kontor-addresses">
      <div class="kontor-sectionhead">
        <div>
          <p class="kontor-eyebrow">Locations</p>
          <h3>Addresses</h3>
        </div>
        <span class="kontor-secondary"><?= $e(count($addresses)) ?> saved</span>
      </div>

      <?php if ($addresses): ?>
        <div class="kontor-addressgrid">
          <?php foreach ($addresses as $address): ?>
            <article class="kontor-address">
              <div class="kontor-address__top">
                <span class="uk-label kontor-pill<?= $address->isPrimary ? '' : ' kontor-pill--inactive' ?>">
                  <?= $e($address->isPrimary ? 'primary ' . $address->addressType : $address->addressType) ?>
                </span>
                <form class="kontor-inline-action" method="post" action="<?= $e($adminUrl) ?>address/">
                  <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="owner_type" value="<?= $e($entityType) ?>">
                  <input type="hidden" name="owner_uid" value="<?= $e($entity->uid->toString()) ?>">
                  <input type="hidden" name="address_uid" value="<?= $e($address->uid->toString()) ?>">
                  <button type="submit" title="Remove address" onclick="return confirm('Remove this address?')"><i class="fa fa-trash"></i></button>
                </form>
              </div>
              <address>
                <strong><?= $e($address->line1) ?></strong>
                <?php if ($address->line2): ?><span><?= $e($address->line2) ?></span><?php endif; ?>
                <span>
                  <?= $e(implode(', ', array_filter([$address->city, $address->region, $address->postalCode]))) ?>
                </span>
                <span><?= $e($address->countryCode) ?></span>
              </address>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="kontor-relations__empty">No addresses have been added yet.</p>
      <?php endif; ?>

      <form class="kontor-addressform" method="post" action="<?= $e($adminUrl) ?>address/">
        <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="owner_type" value="<?= $e($entityType) ?>">
        <input type="hidden" name="owner_uid" value="<?= $e($entity->uid->toString()) ?>">
        <label>
          <span>Type</span>
          <select name="address_type">
            <option value="billing">Billing</option>
            <option value="shipping">Shipping</option>
            <option value="<?= $entityType === 'contact' ? 'home' : 'work' ?>"><?= $entityType === 'contact' ? 'Home' : 'Work' ?></option>
            <option value="other">Other</option>
          </select>
        </label>
        <label class="kontor-addressform__wide">
          <span>Street address</span>
          <input type="text" name="line1" required placeholder="Street and number">
        </label>
        <label>
          <span>Address line 2</span>
          <input type="text" name="line2" placeholder="Suite, floor">
        </label>
        <label>
          <span>City</span>
          <input type="text" name="city" required>
        </label>
        <label>
          <span>Region</span>
          <input type="text" name="region">
        </label>
        <label>
          <span>Postal code</span>
          <input type="text" name="postal_code">
        </label>
        <label>
          <span>Country</span>
          <input type="text" name="country_code" required maxlength="2" placeholder="US">
        </label>
        <label class="kontor-check">
          <input type="checkbox" name="is_primary" value="1">
          <span>Primary address</span>
        </label>
        <button class="uk-button uk-button-secondary kontor-button kontor-button--ghost" type="submit">
          <i class="fa fa-map-marker"></i> Add address
        </button>
      </form>
    </section>
  <?php endif; ?>
</div>

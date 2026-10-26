<?php

/** @var \ProcessWire\InputfieldForm $form */
/** @var array<string, string> $formValues */
/** @var string $backUrl */
/** @var string $backLabel */
/** @var string $title */
/** @var string $description */
/** @var object|null $entity */
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
/** @var array<int, array<string, mixed>> $intakeFields */
/** @var array<string, mixed> $intakeValues */
/** @var string $intakeError */

$inputfield = static fn (string $name) => $form->getChildByName($name);
$fieldValue = static fn (string $name): string => $formValues[$name] ?? '';
$statusField = $inputfield('status');
$statusOptions = $statusField !== null && method_exists($statusField, 'getOptions')
    ? $statusField->getOptions()
    : [];
$currentStatus = $fieldValue('status') ?: 'active';
$displayName = $fieldValue('display_name') ?: $title;
$initials = trim(mb_substr($fieldValue('first_name'), 0, 1) . mb_substr($fieldValue('last_name'), 0, 1));
if ($initials === '') {
    $initials = mb_substr($displayName, 0, 2);
}
$humanize = static fn (string $value): string => ucwords(str_replace('_', ' ', $value));
$field = static function (
    string $name,
    string $label,
    string $description,
    string $note = '',
    string $type = 'text',
    bool $required = false,
    bool $wide = false,
    string $link = '',
    string $linkLabel = ''
) use ($e, $fieldValue): void {
    ?>
    <label class="kontor-nativefield<?= $wide ? ' kontor-nativefield--wide' : '' ?>">
      <span><?= $e($label) ?><?= $required ? ' *' : '' ?></span>
      <?php if ($description !== ''): ?><small class="kontor-field-description"><?= $e($description) ?></small><?php endif; ?>
      <?php if ($type === 'textarea'): ?>
        <textarea class="uk-textarea" name="<?= $e($name) ?>" rows="5"<?= $required ? ' required' : '' ?>><?= $e($fieldValue($name)) ?></textarea>
      <?php elseif ($link !== ''): ?>
        <span class="uk-inline uk-width-1-1">
          <a class="uk-form-icon uk-form-icon-flip" href="<?= $e($link) ?>"<?= str_starts_with($link, 'http') ? ' target="_blank" rel="noopener noreferrer"' : '' ?> title="<?= $e($linkLabel) ?>" aria-label="<?= $e($linkLabel) ?>"><i class="fa fa-external-link"></i></a>
          <input class="uk-input" type="<?= $e($type) ?>" name="<?= $e($name) ?>" value="<?= $e($fieldValue($name)) ?>"<?= $required ? ' required' : '' ?>>
        </span>
      <?php else: ?>
        <input class="uk-input" type="<?= $e($type) ?>" name="<?= $e($name) ?>" value="<?= $e($fieldValue($name)) ?>"<?= $required ? ' required' : '' ?>>
      <?php endif; ?>
      <?php if ($note !== ''): ?><small class="kontor-field-note"><strong>Note:</strong> <?= $e($note) ?></small><?php endif; ?>
    </label>
    <?php
};
$connectedCount = count($workspace['leads']) + count($workspace['deals']) + count($workspace['tasks']);
?>
<div class="ProcessKontor pw-module-workspace kontor-shell kontor-contact-workspace">
  <header class="kontor-formhead">
    <a class="kontor-formhead__back uk-link-reset" href="<?= $e($backUrl) ?>">
      <i class="fa fa-arrow-left"></i> <?= $e($backLabel) ?>
    </a>
    <p class="kontor-eyebrow">Customer directory · Contact</p>
    <p><?= $e($description) ?></p>
  </header>

  <?php if ($duplicates): ?>
    <aside class="uk-alert uk-alert-warning kontor-warning" role="alert">
      <i class="fa fa-exclamation-triangle"></i>
      <div>
        <strong>Possible duplicate contact</strong>
        <p>This email or phone is already used. Review the matching record before creating another contact.</p>
        <div class="uk-flex uk-flex-wrap uk-grid-small uk-margin-small-top" uk-grid>
          <?php foreach ($duplicates as $duplicate): ?>
            <div><a class="uk-button uk-button-default uk-button-small" href="<?= $e($adminUrl) ?>contact/?id=<?= $e(rawurlencode($duplicate->uid->toString())) ?>">Open <?= $e($duplicate->displayName) ?></a></div>
          <?php endforeach; ?>
        </div>
      </div>
    </aside>
  <?php endif; ?>

  <?php if ($entity !== null): ?>
    <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card kontor-contact-hero">
      <div class="uk-flex uk-flex-middle uk-flex-wrap uk-grid-medium" uk-grid>
        <div class="uk-width-expand@m">
          <div class="uk-flex uk-flex-middle uk-grid-small" uk-grid>
            <div><span class="kontor-contact-avatar" aria-hidden="true"><?= $e(mb_strtoupper($initials)) ?></span></div>
            <div class="uk-width-expand">
              <div class="uk-flex uk-flex-middle uk-flex-wrap uk-grid-small" uk-grid>
                <div><strong class="kontor-contact-overview">Contact overview</strong></div>
                <div><span class="uk-label kontor-pill<?= $currentStatus === 'active' ? '' : ' kontor-pill--inactive' ?>"><?= $e($humanize($currentStatus)) ?></span></div>
              </div>
              <?php if ($fieldValue('job_title') !== ''): ?><p class="uk-text-meta uk-margin-remove-top"><?= $e($fieldValue('job_title')) ?></p><?php endif; ?>
              <div class="kontor-contact-channels">
                <?php if ($fieldValue('email') !== ''): ?><a class="uk-link-reset" href="mailto:<?= $e($fieldValue('email')) ?>"><i class="fa fa-envelope-o"></i> <?= $e($fieldValue('email')) ?></a><?php endif; ?>
                <?php $primaryPhone = $fieldValue('mobile') ?: $fieldValue('phone'); ?>
                <?php if ($primaryPhone !== ''): ?><a class="uk-link-reset" href="tel:<?= $e($primaryPhone) ?>"><i class="fa fa-phone"></i> <?= $e($primaryPhone) ?></a><?php endif; ?>
                <?php if ($fieldValue('linkedin') !== ''): ?><a class="uk-link-reset" href="<?= $e($fieldValue('linkedin')) ?>" target="_blank" rel="noopener"><i class="fa fa-linkedin-square"></i> LinkedIn</a><?php endif; ?>
              </div>
            </div>
          </div>
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
      </div>
    </section>
  <?php endif; ?>

  <section class="kontor-section-intro uk-margin-top" aria-label="About this section">
    <i class="fa fa-info-circle" aria-hidden="true"></i>
    <div>
      <strong><?= $entity === null ? 'Create a useful customer record' : 'Keep the customer context useful' ?></strong>
      <p><?= $entity === null ? 'Start with a recognizable name and one reliable contact method. You can connect companies, work and locations after saving.' : 'Update identity and communication details here. Connected companies, CRM work and tasks stay available below when their components are installed.' ?></p>
    </div>
  </section>

  <form class="uk-form-stacked" method="post" action="./<?= $entity !== null ? '?id=' . $e(rawurlencode($entity->uid->toString())) : '' ?>">
    <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
    <?php if ($duplicates): ?>
      <label class="uk-alert uk-alert-warning kontor-confirm-duplicate"><input class="uk-checkbox" type="checkbox" name="confirm_duplicate" value="1"> <span>I reviewed the possible matches and still want to create this contact.</span></label>
    <?php endif; ?>
    <?php if ($intakeError !== ''): ?><div class="uk-alert-danger" uk-alert><p><?= $e($intakeError) ?></p></div><?php endif; ?>
    <div class="uk-grid-medium" uk-grid>
      <div class="uk-width-2-3@l">
        <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card">
          <header class="kontor-sectionhead">
            <div><p class="kontor-eyebrow">Identity</p><h3>Contact details</h3><p class="uk-text-meta uk-margin-small-top">The customer-facing information teammates use across Kontor.</p></div>
          </header>
          <div class="kontor-nativeform kontor-nativeform--embedded">
            <?php $field('display_name', 'Display name', 'The recognizable name shown in lists, search and linked records.', 'Use the name teammates will naturally search for.', 'text', true, true); ?>
            <?php $field('first_name', 'First name', 'Used for greetings and document personalization.'); ?>
            <?php $field('last_name', 'Last name', 'Used for sorting and formal communication.'); ?>
            <?php $field('job_title', 'Job title', 'The person’s role at their company.', 'Keep the role current when responsibilities change.'); ?>
            <label class="kontor-nativefield">
              <span>Status</span>
              <small class="kontor-field-description">Controls whether this contact appears in active workflows and selections.</small>
              <select class="uk-select" name="status">
                <?php foreach ($statusOptions as $value => $label): ?><option value="<?= $e((string) $value) ?>"<?= (string) $value === $currentStatus ? ' selected' : '' ?>><?= $e((string) $label) ?></option><?php endforeach; ?>
              </select>
              <small class="kontor-field-note"><strong>Note:</strong> Use inactive to preserve history without offering this contact for new work.</small>
            </label>
          </div>
        </section>

        <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card uk-margin-top">
          <header class="kontor-sectionhead"><div><p class="kontor-eyebrow">Communication</p><h3>How to reach this person</h3></div></header>
          <div class="kontor-nativeform kontor-nativeform--embedded">
            <?php $field('email', 'Email', 'Primary address for messages and duplicate detection.', 'Use a direct business address when possible.', 'email', false, false, $fieldValue('email') !== '' ? 'mailto:' . $fieldValue('email') : '', 'Email this person'); ?>
            <?php $field('linkedin', 'LinkedIn', 'The person’s profile address, for when email is not published.', '', 'url', false, false, $fieldValue('linkedin'), 'Open the profile on LinkedIn'); ?>
            <?php $field('phone', 'Phone', 'Main office or switchboard number.'); ?>
            <?php $field('mobile', 'Mobile', 'Direct number for time-sensitive communication.', 'Include the country code for international teams.'); ?>
          </div>
        </section>

        <?php if ($intakeFields !== []): ?>
          <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card uk-margin-top">
            <header class="kontor-sectionhead"><div><p class="kontor-eyebrow">Customer profile</p><h3>Relationship context</h3><p class="uk-text-meta uk-margin-small-top">Capture the profile details configured for this organization.</p></div></header>
            <div class="kontor-nativeform kontor-nativeform--embedded"><?php require __DIR__ . '/crm-intake-fields.php'; ?></div>
          </section>
        <?php endif; ?>

        <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card uk-margin-top">
          <header class="kontor-sectionhead"><div><p class="kontor-eyebrow">Shared context</p><h3>Internal notes</h3></div></header>
          <div class="kontor-nativeform kontor-nativeform--embedded">
            <?php $field('notes', 'Notes', 'Concise context that helps teammates work with this customer.', 'Avoid passwords, secrets and unnecessary personal information.', 'textarea', false, true); ?>
          </div>
        </section>
      </div>

      <aside class="uk-width-1-3@l">
        <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card kontor-contact-savecard">
          <p class="kontor-eyebrow">Record readiness</p>
          <h3><?= $entity === null ? 'Ready to create?' : 'Keep it accurate' ?></h3>
          <ul class="uk-list uk-list-divider kontor-checklist">
            <li><i class="fa fa-check-circle"></i><span>A recognizable display name is required.</span></li>
            <li><i class="fa fa-envelope-o"></i><span>Add at least one reliable way to reach the person.</span></li>
            <li><i class="fa fa-history"></i><span>Changes remain available to connected workflows.</span></li>
          </ul>
          <button class="uk-button uk-button-primary uk-width-1-1" type="submit" name="submit_save" value="1"><i class="fa fa-save"></i> Save contact</button>
          <a class="uk-button uk-button-default uk-width-1-1 uk-margin-small-top" href="<?= $e($backUrl) ?>">Cancel</a>
        </section>
      </aside>
    </div>
  </form>

  <?php if ($entity !== null): ?>
    <?php if ($workspace['available']): ?>
      <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card uk-margin-top">
        <header class="kontor-sectionhead">
          <div><p class="kontor-eyebrow">Connected workspace</p><h3>Customer work</h3><p class="uk-text-meta uk-margin-small-top">CRM opportunities and tasks connected to this contact.</p></div>
          <span class="uk-label kontor-pill"><?= $e((string) $connectedCount) ?> linked</span>
        </header>
        <?php if ($connectedCount === 0): ?>
          <div class="uk-placeholder uk-text-center uk-margin-top"><p class="uk-margin-remove">No work is connected yet. Use the actions at the top to start the next step.</p></div>
        <?php else: ?>
          <div class="uk-child-width-1-3@m uk-grid-small uk-grid-match uk-margin-top" uk-grid>
            <?php foreach ([['title' => 'Leads', 'route' => 'crm-lead/', 'icon' => 'user-plus', 'records' => $workspace['leads']], ['title' => 'Deals', 'route' => 'crm-deal/', 'icon' => 'handshake-o', 'records' => $workspace['deals']], ['title' => 'Tasks', 'route' => 'task/', 'icon' => 'check-square-o', 'records' => $workspace['tasks']]] as $group): ?>
              <?php if ($group['records'] !== []): ?><div><div class="kontor-contact-workgroup"><h4><i class="fa fa-<?= $e($group['icon']) ?>"></i> <?= $e($group['title']) ?></h4><ul class="uk-list uk-list-divider"><?php foreach ($group['records'] as $record): ?><li><div><strong><?= $e($record->title) ?></strong><span class="uk-text-meta"><?= $e($humanize($record->status)) ?></span></div><a class="uk-button uk-button-default uk-button-small" href="<?= $e($adminUrl . $group['route']) ?>?id=<?= $e(rawurlencode($record->uid->toString())) ?>">Open</a></li><?php endforeach; ?></ul></div></div><?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <div class="uk-grid-medium uk-margin-top" uk-grid>
      <div class="uk-width-1-2@l">
        <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card">
          <header class="kontor-sectionhead"><div><p class="kontor-eyebrow">Affiliations</p><h3>Company relationships</h3><p class="uk-text-meta uk-margin-small-top">Connect the person to companies without duplicating customer data.</p></div><span class="uk-label kontor-pill"><?= $e((string) count($relationships)) ?> linked</span></header>
          <?php if ($relationships): ?><div class="kontor-relationlist"><?php foreach ($relationships as $relationship): ?><?php $membership = $relationship['membership']; $related = $relationship['entity']; ?><article class="kontor-relation"><span class="kontor-avatar"><?= $e(mb_substr($related->legalName, 0, 2)) ?></span><div class="kontor-relation__body"><strong><?= $e($related->legalName) ?></strong><span class="kontor-secondary"><?= $e($humanize($membership->role ?: 'member')) ?><?= $membership->department ? ' · ' . $e($membership->department) : '' ?></span></div><a class="uk-button uk-button-default uk-button-small" href="<?= $e($adminUrl) ?>company/?id=<?= $e(rawurlencode($related->uid->toString())) ?>">Open</a><?php if ($membership->endedAt === null): ?><form class="kontor-inline-action" method="post" action="<?= $e($adminUrl) ?>relationship/"><input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>"><input type="hidden" name="action" value="end"><input type="hidden" name="contact_id" value="<?= $e($entity->uid->toString()) ?>"><input type="hidden" name="company_id" value="<?= $e($related->uid->toString()) ?>"><button class="uk-button uk-button-text" type="submit" title="End relationship" aria-label="End relationship"><i class="fa fa-unlink"></i></button></form><?php endif; ?></article><?php endforeach; ?></div><?php else: ?><div class="uk-placeholder uk-text-center uk-margin-top"><p class="uk-margin-remove">This contact is not linked to a company yet.</p></div><?php endif; ?>
          <?php if ($availableCompanies): ?><form class="uk-form-stacked kontor-nativeform kontor-nativeform--embedded kontor-related-form" method="post" action="<?= $e($adminUrl) ?>relationship/"><input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="contact_id" value="<?= $e($entity->uid->toString()) ?>"><label class="kontor-nativefield kontor-nativefield--wide"><span>Company *</span><small class="kontor-field-description">Choose the organization this person represents.</small><select class="uk-select" name="company_id" required><option value="">Choose a company</option><?php foreach ($availableCompanies as $company): ?><option value="<?= $e($company->uid->toString()) ?>"><?= $e($company->legalName) ?></option><?php endforeach; ?></select></label><label class="kontor-nativefield"><span>Role</span><small class="kontor-field-description">Their influence or responsibility in the relationship.</small><input class="uk-input" type="text" name="role" placeholder="Decision maker"></label><label class="kontor-nativefield"><span>Department</span><small class="kontor-field-description">The team or business area they work in.</small><input class="uk-input" type="text" name="department" placeholder="Operations"></label><div class="kontor-nativeform__actions"><button class="uk-button uk-button-default" type="submit"><i class="fa fa-link"></i> Link company</button></div></form><?php else: ?><p class="uk-text-meta uk-margin-top">Create a company first when this person needs an organizational affiliation.</p><?php endif; ?>
        </section>
      </div>

      <div class="uk-width-1-2@l">
        <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card">
          <header class="kontor-sectionhead"><div><p class="kontor-eyebrow">Classification</p><h3>Tags</h3><p class="uk-text-meta uk-margin-small-top">Use a small shared vocabulary for segments and routing.</p></div></header>
          <div class="kontor-taglist kontor-taglist--left"><?php foreach ($tags as $tag): ?><span class="kontor-tag"><?= $e($tag) ?></span><?php endforeach; ?><?php if (!$tags): ?><span class="uk-text-meta">No tags assigned.</span><?php endif; ?></div>
          <form class="uk-form-stacked kontor-related-form" method="post" action="<?= $e($adminUrl) ?>tags/"><input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>"><input type="hidden" name="owner_type" value="contact"><input type="hidden" name="owner_uid" value="<?= $e($entity->uid->toString()) ?>"><label class="kontor-nativefield"><span>Tags</span><small class="kontor-field-description">Separate tags with commas, for example: customer, partner, vip.</small><input class="uk-input" type="text" name="tags" value="<?= $e(implode(', ', $tags)) ?>"></label><button class="uk-button uk-button-default uk-margin-small-top" type="submit"><i class="fa fa-tags"></i> Update tags</button></form>
        </section>

        <?php if ($aiReady): ?><section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card uk-margin-top"><header class="kontor-sectionhead"><div><p class="kontor-eyebrow">Optional AI</p><h3>Contact brief</h3><p class="uk-text-meta uk-margin-small-top">Summarize notes, tags and company context for a quick handover.</p></div><?php if ($aiSummary !== null): ?><span class="uk-label kontor-pill"><?= $e($aiSummary['simulated'] ? 'Preview' : 'Generated') ?></span><?php endif; ?></header><?php if ($aiSummary !== null): ?><p><?= $e($aiSummary['summary']) ?></p><?php endif; ?><form method="post" action="<?= $e($adminUrl) ?>contact-ai-summary/"><input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>"><input type="hidden" name="contact_uid" value="<?= $e($entity->uid->toString()) ?>"><input type="hidden" name="simulate" value="1"><button class="uk-button uk-button-default" type="submit"><i class="fa fa-magic"></i> <?= $aiSummary === null ? 'Generate brief' : 'Refresh brief' ?></button></form></section><?php endif; ?>
      </div>
    </div>

    <section class="uk-card uk-card-default uk-card-small uk-card-body kontor-card uk-margin-top">
      <header class="kontor-sectionhead"><div><p class="kontor-eyebrow">Locations</p><h3>Addresses</h3><p class="uk-text-meta uk-margin-small-top">Save billing, shipping or home locations only when operationally useful.</p></div><span class="uk-label kontor-pill"><?= $e((string) count($addresses)) ?> saved</span></header>
      <?php if ($addresses): ?><div class="kontor-addressgrid"><?php foreach ($addresses as $address): ?><article class="kontor-address"><div class="kontor-address__top"><span class="uk-label kontor-pill<?= $address->isPrimary ? '' : ' kontor-pill--inactive' ?>"><?= $e($address->isPrimary ? 'Primary ' . $address->addressType : $address->addressType) ?></span><form class="kontor-inline-action" method="post" action="<?= $e($adminUrl) ?>address/"><input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="owner_type" value="contact"><input type="hidden" name="owner_uid" value="<?= $e($entity->uid->toString()) ?>"><input type="hidden" name="address_uid" value="<?= $e($address->uid->toString()) ?>"><button class="uk-button uk-button-text" type="submit" title="Remove address" aria-label="Remove address" onclick="return confirm('Remove this address?')"><i class="fa fa-trash"></i></button></form></div><address><strong><?= $e($address->line1) ?></strong><?php if ($address->line2): ?><span><?= $e($address->line2) ?></span><?php endif; ?><span><?= $e(implode(', ', array_filter([$address->city, $address->region, $address->postalCode]))) ?></span><span><?= $e($address->countryCode) ?></span></address></article><?php endforeach; ?></div><?php endif; ?>
      <form class="uk-form-stacked kontor-nativeform kontor-nativeform--embedded kontor-related-form" method="post" action="<?= $e($adminUrl) ?>address/"><input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="owner_type" value="contact"><input type="hidden" name="owner_uid" value="<?= $e($entity->uid->toString()) ?>"><label class="kontor-nativefield"><span>Type</span><small class="kontor-field-description">Choose how this location is used.</small><select class="uk-select" name="address_type"><option value="billing">Billing</option><option value="shipping">Shipping</option><option value="home">Home</option><option value="other">Other</option></select></label><label class="kontor-nativefield"><span>Street address *</span><small class="kontor-field-description">Street name and building number.</small><input class="uk-input" type="text" name="line1" required></label><label class="kontor-nativefield"><span>Address line 2</span><small class="kontor-field-description">Suite, floor or unit when needed.</small><input class="uk-input" type="text" name="line2"></label><label class="kontor-nativefield"><span>City *</span><small class="kontor-field-description">Town or locality.</small><input class="uk-input" type="text" name="city" required></label><label class="kontor-nativefield"><span>Region</span><small class="kontor-field-description">State, province or region.</small><input class="uk-input" type="text" name="region"></label><label class="kontor-nativefield"><span>Postal code</span><small class="kontor-field-description">Local postal or ZIP code.</small><input class="uk-input" type="text" name="postal_code"></label><label class="kontor-nativefield"><span>Country *</span><small class="kontor-field-description">Use the two-letter country code.</small><input class="uk-input" type="text" name="country_code" maxlength="2" placeholder="DE" required></label><label class="kontor-contact-checkbox"><input class="uk-checkbox" type="checkbox" name="is_primary" value="1"><span>Use as primary address</span></label><div class="kontor-nativeform__actions"><button class="uk-button uk-button-default" type="submit"><i class="fa fa-map-marker"></i> Add address</button></div></form>
    </section>
  <?php endif; ?>
</div>

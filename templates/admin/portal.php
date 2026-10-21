<?php

/** @var \Kontor\Portal\Domain\PortalAccount[] $accounts */
/** @var \Kontor\Contacts\Domain\Contact[] $contacts */
/** @var \Kontor\Portal\Domain\PortalAccount|null $selected */
/** @var \Kontor\Contacts\Domain\Contact|null $contact */
/** @var array<int, array{quotation: \Kontor\Sales\Domain\Quotation, files: array<int, array<string, mixed>>}> $quotationRows */
/** @var array<int, array{invoice: \Kontor\Invoices\Domain\Invoice, payments: array<int, array<string, mixed>>, files: array<int, array<string, mixed>>}> $invoiceRows */
/** @var array{ok: bool, message: string}|null $verification */
/** @var string $adminUrl */
/** @var string $csrfName */
/** @var string $csrfValue */
/** @var callable $e */

$money = static fn (\Kontor\SDK\ValueObjects\Money $value): string =>
    number_format($value->amountMinor() / 100, 2, '.', '') . ' ' . $value->currencyCode();
$contactLabels = [];
$accountContactUids = [];
foreach ($contacts as $candidate) {
    $contactLabels[$candidate->uid->toString()] = $candidate->displayName;
}
foreach ($accounts as $account) {
    $accountContactUids[$account->contactUid] = true;
}
$availableContacts = array_values(array_filter(
    $contacts,
    static fn ($candidate): bool => !isset($accountContactUids[$candidate->uid->toString()])
));
$activeCount = count(array_filter($accounts, static fn ($account): bool => $account->isActive()));
$recentLoginCount = count(array_filter(
    $accounts,
    static fn ($account): bool => $account->lastLoginAt !== null
        && $account->lastLoginAt >= new \DateTimeImmutable('-30 days')
));
$statusLabel = static fn (string $status): string => ucwords(str_replace('_', ' ', $status));
?>
<div class="ProcessKontor pw-module-workspace kontor-shell">
  <header class="pw-module-head kontor-pagehead">
    <div>
      <p class="kontor-eyebrow"><?= $selected === null ? 'Customer access' : 'Portal account' ?></p>
      <h2><?= $e($selected !== null && $contact !== null ? $contact->displayName : 'Customer portal') ?></h2>
      <p><?= $selected === null
          ? 'Invite customers into one secure self-service space for their profile, quotations, invoices, payments and shared files.'
          : 'Manage this customer’s access and review exactly what is available in their self-service workspace.' ?></p>
    </div>
    <div class="pw-module-actions kontor-pagehead__actions">
      <?php if ($selected !== null): ?>
        <a class="uk-button uk-button-default" href="<?= $e($adminUrl) ?>portal/"><i class="fa fa-arrow-left"></i> All accounts</a>
      <?php elseif ($availableContacts !== []): ?>
        <button class="uk-button uk-button-primary" type="button" uk-toggle="target: #kontor-portal-account-modal"><i class="fa fa-user-plus"></i> Create access</button>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($verification !== null): ?>
    <div class="<?= $verification['ok'] ? 'uk-alert-success' : 'uk-alert-danger' ?> uk-margin-medium-bottom" uk-alert>
      <a class="uk-alert-close" uk-close></a>
      <p><strong><?= $e($verification['ok'] ? 'Sign-in works.' : 'Sign-in failed.') ?></strong> <?= $e($verification['message']) ?></p>
    </div>
  <?php endif; ?>

  <?php if ($selected === null): ?>
    <div class="uk-grid-small uk-child-width-1-1 uk-child-width-1-3@m uk-margin-medium-bottom" uk-grid>
      <div><div class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat"><span class="kontor-stat__icon"><i class="fa fa-users"></i></span><span><strong class="kontor-stat__value"><?= $e((string) count($accounts)) ?></strong><span class="kontor-stat__label">Portal accounts</span></span></div></div>
      <div><div class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat"><span class="kontor-stat__icon kontor-stat__icon--success"><i class="fa fa-unlock-alt"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $activeCount) ?></strong><span class="kontor-stat__label">Active access</span></span></div></div>
      <div><div class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat"><span class="kontor-stat__icon"><i class="fa fa-sign-in"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $recentLoginCount) ?></strong><span class="kontor-stat__label">Signed in this month</span></span></div></div>
    </div>

    <section class="uk-card uk-card-default uk-card-small uk-card-body uk-margin-medium-bottom">
      <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-grid-small" uk-grid>
        <div>
          <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Access directory</p>
          <h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">Customer accounts</h3>
          <p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom">Open an account to update the customer profile, pause access or review shared commercial documents.</p>
        </div>
        <?php if ($availableContacts !== []): ?><div><button class="uk-button uk-button-default" type="button" uk-toggle="target: #kontor-portal-account-modal"><i class="fa fa-plus"></i> New account</button></div><?php endif; ?>
      </div>

      <?php if ($accounts !== []): ?>
        <div class="uk-overflow-auto uk-visible@m uk-margin">
          <table class="uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small">
            <thead><tr><th>Customer</th><th>Login email</th><th>Access</th><th>Last sign-in</th></tr></thead>
            <tbody><?php foreach ($accounts as $account): $accountUrl = $adminUrl . 'portal/?id=' . rawurlencode($account->uid->toString()); ?><tr>
              <td><a href="<?= $e($accountUrl) ?>"><strong><?= $e($contactLabels[$account->contactUid] ?? 'Unavailable contact') ?></strong></a></td>
              <td><?= $e($account->email) ?></td>
              <td><span class="uk-label<?= $account->isActive() ? ' uk-label-success' : '' ?>"><?= $e($statusLabel($account->status)) ?></span></td>
              <td><?= $e($account->lastLoginAt?->format('M j, Y · H:i') ?? 'Not yet') ?></td>
            </tr><?php endforeach; ?></tbody>
          </table>
        </div>
        <div class="uk-hidden@m uk-margin-small-top">
          <?php foreach ($accounts as $account): $accountUrl = $adminUrl . 'portal/?id=' . rawurlencode($account->uid->toString()); ?>
            <a class="uk-card uk-card-default uk-card-small uk-card-body uk-display-block uk-link-reset uk-margin-small-bottom" href="<?= $e($accountUrl) ?>">
              <div class="uk-flex uk-flex-between uk-flex-top uk-grid-small" uk-grid><div><strong><?= $e($contactLabels[$account->contactUid] ?? 'Unavailable contact') ?></strong><div class="uk-text-meta uk-margin-small-top"><?= $e($account->email) ?></div></div><div><span class="uk-label<?= $account->isActive() ? ' uk-label-success' : '' ?>"><?= $e($statusLabel($account->status)) ?></span></div></div>
              <div class="uk-text-small uk-margin-small-top">Last sign-in: <?= $e($account->lastLoginAt?->format('M j, Y · H:i') ?? 'Not yet') ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="uk-placeholder uk-text-center uk-margin">
          <span class="fa fa-user-circle fa-2x uk-text-muted" aria-hidden="true"></span>
          <h3 class="uk-margin-small-top uk-margin-small-bottom">No customer access yet</h3>
          <p class="uk-text-muted uk-margin-small-top">Create an account for an existing contact, then securely share the login details with them.</p>
          <?php if ($availableContacts !== []): ?><button class="uk-button uk-button-primary" type="button" uk-toggle="target: #kontor-portal-account-modal"><i class="fa fa-user-plus"></i> Create first access</button><?php else: ?><a class="uk-button uk-button-primary" href="<?= $e($adminUrl) ?>contact/"><i class="fa fa-user-plus"></i> Create a contact first</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="uk-card uk-card-default uk-card-small uk-card-body">
      <ul class="uk-margin-remove" uk-accordion><li>
        <a class="uk-accordion-title" href>Help a customer sign in</a>
        <div class="uk-accordion-content">
          <p class="uk-text-muted">Use this check only while troubleshooting with a customer. It confirms whether their current email and password are accepted.</p>
          <form class="uk-form-stacked" method="post" action="<?= $e($adminUrl) ?>portal-verify/">
            <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
            <div class="uk-grid-small" uk-grid>
              <div class="uk-width-1-1 uk-width-1-2@m"><label class="uk-form-label" for="portal-check-email">Login email</label><input class="uk-input uk-margin-small-top" id="portal-check-email" type="email" name="email" autocomplete="off" required></div>
              <div class="uk-width-1-1 uk-width-1-2@m"><label class="uk-form-label" for="portal-check-password">Password</label><input class="uk-input uk-margin-small-top" id="portal-check-password" type="password" name="password" autocomplete="new-password" required></div>
            </div>
            <button class="uk-button uk-button-default uk-margin" type="submit"><i class="fa fa-check-circle"></i> Test sign-in</button>
          </form>
        </div>
      </li></ul>
    </section>
  <?php elseif ($contact !== null): ?>
    <section class="uk-card uk-card-default uk-card-small uk-card-body uk-margin-medium-bottom">
      <div class="uk-grid-medium uk-flex-middle" uk-grid>
        <div class="uk-width-expand@m">
          <div class="uk-flex uk-flex-wrap uk-flex-middle uk-grid-small" uk-grid><div><span class="uk-label<?= $selected->isActive() ? ' uk-label-success' : '' ?>"><?= $e($statusLabel($selected->status)) ?></span></div><div class="uk-text-meta">Created <?= $e($selected->createdAt->format('M j, Y')) ?></div></div>
          <h3 class="uk-card-title uk-margin-small-top uk-margin-small-bottom"><?= $e($selected->email) ?></h3>
          <p class="uk-text-muted uk-margin-remove">Last sign-in: <?= $e($selected->lastLoginAt?->format('M j, Y · H:i') ?? 'This customer has not signed in yet.') ?></p>
        </div>
        <div class="uk-width-auto@m">
          <form method="post" action="<?= $e($adminUrl) ?>portal-status/" data-kontor-confirm="<?= $e($selected->isActive() ? 'Pause this customer’s portal access?' : 'Restore this customer’s portal access?') ?>">
            <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>"><input type="hidden" name="account_uid" value="<?= $e($selected->uid->toString()) ?>"><input type="hidden" name="action" value="<?= $e($selected->isActive() ? 'disable' : 'enable') ?>">
            <button class="uk-button uk-button-default" type="submit"><i class="fa fa-<?= $selected->isActive() ? 'pause' : 'play' ?>"></i> <?= $e($selected->isActive() ? 'Pause access' : 'Restore access') ?></button>
          </form>
        </div>
      </div>
    </section>

    <div class="uk-grid-medium uk-margin-medium-bottom" uk-grid>
      <div class="uk-width-1-1 uk-width-1-3@l">
        <section class="uk-card uk-card-default uk-card-small uk-card-body">
          <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Customer profile</p><h3 class="uk-card-title uk-margin-small-top">Contact details</h3>
          <p class="uk-text-muted">These are the customer-safe details available in the portal. Company and internal CRM fields remain private.</p>
          <form class="uk-form-stacked" method="post" action="<?= $e($adminUrl) ?>portal-profile/">
            <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>"><input type="hidden" name="account_uid" value="<?= $e($selected->uid->toString()) ?>">
            <div class="uk-margin-small"><label class="uk-form-label" for="portal-first-name">First name</label><input class="uk-input uk-margin-small-top" id="portal-first-name" name="firstName" value="<?= $e($contact->firstName) ?>"></div>
            <div class="uk-margin-small"><label class="uk-form-label" for="portal-middle-name">Middle name</label><input class="uk-input uk-margin-small-top" id="portal-middle-name" name="middleName" value="<?= $e($contact->middleName) ?>"></div>
            <div class="uk-margin-small"><label class="uk-form-label" for="portal-last-name">Last name</label><input class="uk-input uk-margin-small-top" id="portal-last-name" name="lastName" value="<?= $e($contact->lastName) ?>"></div>
            <div class="uk-margin-small"><label class="uk-form-label" for="portal-phone">Phone</label><input class="uk-input uk-margin-small-top" id="portal-phone" type="tel" name="phone" value="<?= $e($contact->phone) ?>"></div>
            <div class="uk-margin-small"><label class="uk-form-label" for="portal-mobile">Mobile</label><input class="uk-input uk-margin-small-top" id="portal-mobile" type="tel" name="mobile" value="<?= $e($contact->mobile) ?>"></div>
            <div class="uk-margin-small"><label class="uk-form-label" for="portal-language">Preferred language</label><input class="uk-input uk-margin-small-top" id="portal-language" name="preferredLanguage" value="<?= $e($contact->preferredLanguage) ?>" maxlength="10" placeholder="For example, en or de"><div class="uk-text-meta uk-margin-small-top">Used for customer-facing messages and documents.</div></div>
            <button class="uk-button uk-button-primary uk-width-1-1 uk-margin-small-top" type="submit"><i class="fa fa-check"></i> Save profile</button>
          </form>
          <a class="uk-button uk-button-text uk-margin-small-top" href="<?= $e($adminUrl) ?>contact/?id=<?= $e(rawurlencode($contact->uid->toString())) ?>">Open full CRM contact <i class="fa fa-angle-right"></i></a>
        </section>
      </div>
      <div class="uk-width-1-1 uk-width-2-3@l">
        <section class="uk-card uk-card-default uk-card-small uk-card-body uk-margin-medium-bottom">
          <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Visible to customer</p><h3 class="uk-card-title uk-margin-small-top uk-margin-small-bottom">Quotations</h3>
          <p class="uk-text-muted uk-margin-small-top">Published quotations and their shared files appear here exactly as this customer can access them.</p>
          <?php if ($quotationRows !== []): ?>
            <div class="uk-overflow-auto"><table class="uk-table uk-table-divider uk-table-middle uk-table-small"><thead><tr><th>Quotation</th><th>Status</th><th>Total</th><th>Valid until</th><th>Files</th></tr></thead><tbody>
            <?php foreach ($quotationRows as $row): $quotation = $row['quotation']; ?><tr><td><strong><?= $e($quotation->number ?? 'Draft') ?></strong></td><td><span class="uk-label"><?= $e($statusLabel($quotation->status)) ?></span></td><td><?= $e($money($quotation->total)) ?></td><td><?= $e($quotation->validUntil?->format('M j, Y') ?? 'Not set') ?></td><td><?php if ($row['files'] === []): ?><span class="uk-text-muted">None</span><?php else: foreach ($row['files'] as $file): ?><a class="uk-button uk-button-text" href="<?= $e($file['downloadUrl']) ?>"><i class="fa fa-download"></i> <?= $e($file['original_name']) ?></a><?php endforeach; endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
          <?php else: ?><div class="uk-placeholder uk-text-center"><span class="fa fa-file-text-o fa-2x uk-text-muted"></span><p class="uk-text-muted uk-margin-small-top">No quotations are currently shared with this customer.</p></div><?php endif; ?>
        </section>

        <section class="uk-card uk-card-default uk-card-small uk-card-body">
          <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Visible to customer</p><h3 class="uk-card-title uk-margin-small-top uk-margin-small-bottom">Invoices and payments</h3>
          <p class="uk-text-muted uk-margin-small-top">Review billing documents, current amounts due, recorded payments and downloadable files.</p>
          <?php if ($invoiceRows !== []): ?>
            <div class="uk-overflow-auto"><table class="uk-table uk-table-divider uk-table-middle uk-table-small"><thead><tr><th>Invoice</th><th>Status</th><th>Total</th><th>Amount due</th><th>Payments</th><th>Files</th></tr></thead><tbody>
            <?php foreach ($invoiceRows as $row): $invoice = $row['invoice']; ?><tr><td><strong><?= $e($invoice->number ?? 'Draft') ?></strong></td><td><span class="uk-label"><?= $e($statusLabel($invoice->status)) ?></span></td><td><?= $e($money($invoice->total)) ?></td><td><?= $e($money($invoice->due)) ?></td><td><?php if ($row['payments'] === []): ?><span class="uk-text-muted">None</span><?php else: foreach ($row['payments'] as $paymentRow): ?><?= $e($paymentRow['payment']->number ?? 'Payment') ?> · <?= $e($money($paymentRow['allocatedAmount'])) ?><br><?php endforeach; endif; ?></td><td><?php if ($row['files'] === []): ?><span class="uk-text-muted">None</span><?php else: foreach ($row['files'] as $file): ?><a class="uk-button uk-button-text" href="<?= $e($file['downloadUrl']) ?>"><i class="fa fa-download"></i> <?= $e($file['original_name']) ?></a><?php endforeach; endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
          <?php else: ?><div class="uk-placeholder uk-text-center"><span class="fa fa-credit-card fa-2x uk-text-muted"></span><p class="uk-text-muted uk-margin-small-top">No invoices are currently shared with this customer.</p></div><?php endif; ?>
        </section>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($selected === null && $availableContacts !== []): ?>
    <div id="kontor-portal-account-modal" uk-modal>
      <div class="uk-modal-dialog uk-modal-body">
        <a class="uk-modal-close-default" href="#" role="button" uk-close aria-label="Close"></a>
        <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Customer access</p>
        <h2 class="uk-modal-title uk-margin-small-top">Create portal account</h2>
        <p class="uk-text-muted">Connect an existing CRM contact and choose the email they will use to sign in. Share the temporary password through a secure channel.</p>
        <form class="uk-form-stacked" method="post" action="<?= $e($adminUrl) ?>portal-account/">
          <input type="hidden" name="<?= $e($csrfName) ?>" value="<?= $e($csrfValue) ?>">
          <div class="uk-margin"><label class="uk-form-label" for="portal-contact">Customer contact</label><select class="uk-select uk-margin-small-top" id="portal-contact" name="contact_uid" required><option value="">Select a contact</option><?php foreach ($availableContacts as $candidate): ?><option value="<?= $e($candidate->uid->toString()) ?>"><?= $e($candidate->displayName) ?><?= $candidate->email !== null ? ' · ' . $e($candidate->email) : '' ?></option><?php endforeach; ?></select><div class="uk-text-meta uk-margin-small-top">Each contact can have one portal account.</div></div>
          <div class="uk-margin"><label class="uk-form-label" for="portal-login-email">Login email</label><input class="uk-input uk-margin-small-top" id="portal-login-email" type="email" name="email" placeholder="customer@example.com" autocomplete="off" required><div class="uk-text-meta uk-margin-small-top">Use the address this customer expects to use for portal access.</div></div>
          <div class="uk-margin"><label class="uk-form-label" for="portal-password">Temporary password</label><input class="uk-input uk-margin-small-top" id="portal-password" type="password" name="password" minlength="12" autocomplete="new-password" required><div class="uk-text-meta uk-margin-small-top">Use at least 12 characters. The customer can use this credential to sign in immediately.</div></div>
          <div class="uk-flex uk-flex-right uk-flex-middle uk-grid-small" uk-grid><div><button class="uk-button uk-button-default uk-modal-close" type="button">Cancel</button></div><div><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-unlock-alt"></i> Create access</button></div></div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

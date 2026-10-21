<?php

/** @var \Kontor\Invoices\Domain\Invoice[] $invoices */
/** @var \Kontor\Invoices\Domain\Invoice[] $allInvoices */
/** @var array<string, string> $customerLabels */
/** @var string $query */
/** @var string $selectedStatus */
/** @var string $selectedKind */
/** @var bool $canViewSales */
/** @var string $adminUrl */
/** @var callable $e */

$money = static fn (\Kontor\SDK\ValueObjects\Money $value): string =>
    number_format($value->amountMinor() / 100, 2, '.', ',') . ' ' . $value->currencyCode();
$humanize = static fn (string $value): string => ucwords(str_replace('_', ' ', $value));
$statusClass = static fn (string $status): string => match ($status) {
    'paid' => ' uk-label-success',
    'cancelled' => ' uk-label-danger',
    'sent', 'overdue', 'partially_paid' => ' uk-label-warning',
    default => '',
};
$today = new DateTimeImmutable('today');
$isOutstanding = static fn ($invoice): bool =>
    $invoice->due->amountMinor() > 0
    && in_array($invoice->status, ['issued', 'sent', 'overdue', 'partially_paid'], true);
$isOverdue = static fn ($invoice): bool =>
    $invoice->due->amountMinor() > 0
    && ($invoice->status === 'overdue'
        || ($invoice->dueDate !== null && $invoice->dueDate < $today
            && !in_array($invoice->status, ['draft', 'paid', 'cancelled'], true)));
$allCount = count($allInvoices);
$draftCount = count(array_filter($allInvoices, static fn ($invoice): bool => $invoice->status === 'draft'));
$outstandingInvoices = array_values(array_filter($allInvoices, $isOutstanding));
$overdueCount = count(array_filter($allInvoices, $isOverdue));
$outstandingByCurrency = [];
foreach ($outstandingInvoices as $outstandingInvoice) {
    $currency = $outstandingInvoice->currencyCode;
    $outstandingByCurrency[$currency] = ($outstandingByCurrency[$currency] ?? 0)
        + $outstandingInvoice->due->amountMinor();
}
$outstandingSummary = count($outstandingByCurrency) === 1
    ? number_format(reset($outstandingByCurrency) / 100, 2, '.', ',') . ' ' . key($outstandingByCurrency)
    : (count($outstandingInvoices) === 0 ? 'No open balance' : count($outstandingInvoices) . ' open balances');
$filtersActive = $query !== '' || $selectedStatus !== '' || $selectedKind !== '';
$statusUrl = static function (string $status) use ($adminUrl): string {
    return $adminUrl . 'invoices/' . ($status !== '' ? '?' . http_build_query(['status' => $status]) : '');
};
$duePresentation = static function ($invoice) use ($today, $isOverdue): array {
    if ($invoice->dueDate === null) {
        return ['label' => 'Not set', 'meta' => $invoice->isDraft() ? 'Draft' : 'No due date', 'class' => ''];
    }
    if ($isOverdue($invoice)) {
        return ['label' => $invoice->dueDate->format('M j, Y'), 'meta' => 'Overdue', 'class' => 'uk-text-danger'];
    }
    if ($invoice->due->amountMinor() === 0) {
        return ['label' => $invoice->dueDate->format('M j, Y'), 'meta' => 'Settled', 'class' => ''];
    }

    return ['label' => $invoice->dueDate->format('M j, Y'), 'meta' => 'Payment due', 'class' => ''];
};
?>
<div class="ProcessKontor pw-module-workspace kontor-shell">
  <header class="pw-module-head kontor-pagehead">
    <div>
      <p class="kontor-eyebrow">Accounts receivable</p>
      <h2>Invoices</h2>
      <p>See what must be issued, delivered or collected, then open the billing record to continue.</p>
    </div>
    <?php if ($canViewSales): ?><div class="pw-module-actions kontor-pagehead__actions"><a class="uk-button uk-button-primary uk-link-reset" href="<?= $e($adminUrl) ?>sales/"><i class="fa fa-shopping-cart"></i> Create from sales order</a></div><?php endif; ?>
  </header>

  <div class="uk-grid-small uk-child-width-1-1 uk-child-width-1-2@s uk-child-width-1-4@l uk-margin-medium-bottom" uk-grid>
    <div><a class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat uk-link-reset<?= $selectedStatus === '' ? ' kontor-card--selected' : '' ?>" href="<?= $e($statusUrl('')) ?>"<?= $selectedStatus === '' ? ' aria-current="page"' : '' ?>><span class="kontor-stat__icon"><i class="fa fa-file-text-o"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $allCount) ?></strong><span class="kontor-stat__label">All documents</span></span></a></div>
    <div><a class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat uk-link-reset<?= $selectedStatus === 'draft' ? ' kontor-card--selected' : '' ?>" href="<?= $e($statusUrl('draft')) ?>"<?= $selectedStatus === 'draft' ? ' aria-current="page"' : '' ?>><span class="kontor-stat__icon"><i class="fa fa-pencil-square-o"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $draftCount) ?></strong><span class="kontor-stat__label">Drafts to review</span></span></a></div>
    <div><a class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat uk-link-reset<?= $selectedStatus === 'outstanding' ? ' kontor-card--selected' : '' ?>" href="<?= $e($statusUrl('outstanding')) ?>"<?= $selectedStatus === 'outstanding' ? ' aria-current="page"' : '' ?>><span class="kontor-stat__icon"><i class="fa fa-credit-card"></i></span><span><strong class="kontor-stat__value"><?= $e((string) count($outstandingInvoices)) ?></strong><span class="kontor-stat__label">Outstanding · <?= $e($outstandingSummary) ?></span></span></a></div>
    <div><a class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat uk-link-reset<?= $selectedStatus === 'overdue' ? ' kontor-card--selected' : '' ?>" href="<?= $e($statusUrl('overdue')) ?>"<?= $selectedStatus === 'overdue' ? ' aria-current="page"' : '' ?>><span class="kontor-stat__icon<?= $overdueCount > 0 ? ' kontor-stat__icon--danger' : '' ?>"><i class="fa fa-exclamation-triangle"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $overdueCount) ?></strong><span class="kontor-stat__label">Overdue</span></span></a></div>
  </div>

  <section class="uk-card uk-card-default uk-card-small uk-card-body">
    <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-grid-small" uk-grid>
      <div><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Billing queue</p><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom"><?= $e(match ($selectedStatus) { 'draft' => 'Draft invoices', 'issued' => 'Ready to deliver', 'sent' => 'Sent invoices', 'overdue' => 'Overdue invoices', 'partially_paid' => 'Partially paid', 'outstanding' => 'Outstanding balances', 'paid' => 'Paid invoices', 'cancelled' => 'Cancelled invoices', default => 'All invoices and credit notes' }) ?></h3><p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom"><?= $e((string) count($invoices)) ?> document<?= count($invoices) === 1 ? '' : 's' ?> shown</p></div>
    </div>

    <form class="uk-form-stacked uk-margin" method="get" action="<?= $e($adminUrl) ?>invoices/">
      <div class="uk-grid-small uk-flex-bottom" uk-grid>
        <div class="uk-width-1-1 uk-width-expand@m"><label class="uk-form-label" for="invoices-search">Search invoices</label><div class="uk-inline uk-width-1-1 uk-margin-small-top"><span class="uk-form-icon" uk-icon="icon: search"></span><input class="uk-input" id="invoices-search" type="search" name="q" value="<?= $e($query) ?>" placeholder="Number or customer name"></div><div class="uk-text-meta uk-margin-small-top">Find a billing document by its number or customer.</div></div>
        <div class="uk-width-1-1 uk-width-1-4@m"><label class="uk-form-label" for="invoices-status">Status</label><select class="uk-select uk-margin-small-top" id="invoices-status" name="status"><option value="">All statuses</option><?php foreach (['draft' => 'Draft', 'issued' => 'Issued', 'sent' => 'Sent', 'outstanding' => 'Any outstanding', 'overdue' => 'Overdue', 'partially_paid' => 'Partially paid', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $value => $label): ?><option value="<?= $e($value) ?>"<?= $selectedStatus === $value ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select><div class="uk-text-meta uk-margin-small-top">Focus on the next billing action.</div></div>
        <div class="uk-width-1-1 uk-width-1-5@m"><label class="uk-form-label" for="invoices-kind">Document</label><select class="uk-select uk-margin-small-top" id="invoices-kind" name="kind"><option value="">Invoices and credits</option><option value="invoice"<?= $selectedKind === 'invoice' ? ' selected' : '' ?>>Invoices</option><option value="credit_note"<?= $selectedKind === 'credit_note' ? ' selected' : '' ?>>Credit notes</option></select><div class="uk-text-meta uk-margin-small-top">Choose the billing document type.</div></div>
        <div class="uk-width-1-1 uk-width-auto@m"><button class="uk-button uk-button-default uk-width-1-1" type="submit">Apply</button></div>
        <?php if ($filtersActive): ?><div class="uk-width-1-1 uk-width-auto@m"><a class="uk-button uk-button-text uk-width-1-1" href="<?= $e($adminUrl) ?>invoices/">Reset</a></div><?php endif; ?>
      </div>
    </form>

    <?php if ($invoices !== []): ?>
      <div class="uk-overflow-auto uk-visible@m">
        <table class="uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small uk-margin-remove-bottom">
          <thead><tr><th>Document</th><th>Customer</th><th>Issue date</th><th>Due date</th><th class="uk-text-right">Total</th><th class="uk-text-right">Outstanding</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($invoices as $invoice): $due = $duePresentation($invoice); $invoiceUrl = $adminUrl . 'invoice/?id=' . rawurlencode($invoice->uid->toString()); ?><tr>
            <td><a href="<?= $e($invoiceUrl) ?>"><strong><?= $e($invoice->number ?? 'Draft invoice') ?></strong><div class="uk-text-meta uk-margin-small-top"><?= $e($invoice->kind === 'credit_note' ? 'Credit note' : 'Invoice') ?></div></a></td>
            <td><?= $e($customerLabels[$invoice->customerType . ':' . $invoice->customerUid] ?? 'Customer unavailable') ?></td>
            <td><?= $e($invoice->issueDate?->format('M j, Y') ?? 'Not issued') ?></td>
            <td class="<?= $e($due['class']) ?>"><strong><?= $e($due['label']) ?></strong><div class="uk-text-meta"><?= $e($due['meta']) ?></div></td>
            <td class="uk-text-right"><?= $e($money($invoice->total)) ?></td>
            <td class="uk-text-right"><strong><?= $e($money($invoice->due)) ?></strong></td>
            <td><span class="uk-label<?= $statusClass($invoice->status) ?>"><?= $e($humanize($invoice->status)) ?></span></td>
          </tr><?php endforeach; ?></tbody>
        </table>
      </div>
      <div class="uk-hidden@m">
        <?php foreach ($invoices as $invoice): $due = $duePresentation($invoice); $invoiceUrl = $adminUrl . 'invoice/?id=' . rawurlencode($invoice->uid->toString()); ?>
          <a class="uk-card uk-card-default uk-card-small uk-card-body uk-display-block uk-link-reset uk-margin-small-bottom" href="<?= $e($invoiceUrl) ?>">
            <div class="uk-flex uk-flex-between uk-flex-top uk-grid-small" uk-grid><div class="uk-width-expand"><strong><?= $e($invoice->number ?? 'Draft invoice') ?></strong><div class="uk-text-meta uk-margin-small-top"><?= $e($invoice->kind === 'credit_note' ? 'Credit note' : 'Invoice') ?> · <?= $e($customerLabels[$invoice->customerType . ':' . $invoice->customerUid] ?? 'Customer unavailable') ?></div></div><div><span class="uk-label<?= $statusClass($invoice->status) ?>"><?= $e($humanize($invoice->status)) ?></span></div></div>
            <div class="uk-grid-small uk-child-width-1-2 uk-margin-small-top" uk-grid><div><div class="uk-text-meta">Total</div><strong><?= $e($money($invoice->total)) ?></strong></div><div><div class="uk-text-meta">Outstanding</div><strong><?= $e($money($invoice->due)) ?></strong></div><div><div class="uk-text-meta">Issued</div><strong><?= $e($invoice->issueDate?->format('M j, Y') ?? 'Not issued') ?></strong></div><div><div class="uk-text-meta">Due</div><strong class="<?= $e($due['class']) ?>"><?= $e($due['label']) ?></strong><div class="uk-text-meta"><?= $e($due['meta']) ?></div></div></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="uk-placeholder uk-text-center"><span class="fa fa-file-text-o fa-2x uk-text-muted"></span><h3 class="uk-margin-small-top uk-margin-small-bottom"><?= $filtersActive ? 'No matching invoices' : 'No invoices yet' ?></h3><p class="uk-text-muted uk-margin-small-top"><?= $filtersActive ? 'Try a broader search or reset the filters.' : 'Confirm a sales order, then create its customer invoice from the Sales workspace.' ?></p><?php if ($filtersActive): ?><a class="uk-button uk-button-default uk-link-reset" href="<?= $e($adminUrl) ?>invoices/">Reset filters</a><?php elseif ($canViewSales): ?><a class="uk-button uk-button-primary uk-link-reset" href="<?= $e($adminUrl) ?>sales/">Open sales orders</a><?php endif; ?></div>
    <?php endif; ?>
  </section>
</div>

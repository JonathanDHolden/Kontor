<?php

/** @var \Kontor\Sales\Domain\Quotation[] $quotations */
/** @var \Kontor\Sales\Domain\Order[] $orders */
/** @var array<string, string> $customerLabels */
/** @var bool $canCreateQuotation */
/** @var bool $canViewOrders */
/** @var bool $showCatalog */
/** @var bool $showInvoices */
/** @var string $adminUrl */
/** @var callable $e */

$money = static fn (\Kontor\SDK\ValueObjects\Money $value): string =>
    number_format($value->amountMinor() / 100, 2, '.', ',') . ' ' . $value->currencyCode();
$humanize = static fn (string $value): string => ucwords(str_replace('_', ' ', $value));
$customer = static fn (string $type, string $uid): string =>
    $customerLabels[$type . ':' . $uid] ?? 'Customer unavailable';
$statusClass = static fn (string $status): string => match ($status) {
    'accepted', 'completed', 'paid', 'fulfilled' => ' uk-label-success',
    'rejected', 'cancelled', 'expired' => ' uk-label-danger',
    'sent', 'issued', 'confirmed' => ' uk-label-warning',
    default => '',
};
$draftQuotations = count(array_filter($quotations, static fn ($quotation): bool => $quotation->status === 'draft'));
$openQuotations = count(array_filter($quotations, static fn ($quotation): bool => $quotation->isOpen()));
$acceptedQuotations = count(array_filter($quotations, static fn ($quotation): bool => $quotation->isAccepted()));
$openOrders = count(array_filter($orders, static fn ($order): bool => $order->isOpen()));
?>
<div class="ProcessKontor pw-module-workspace kontor-shell">
  <header class="pw-module-head kontor-pagehead">
    <div>
      <p class="kontor-eyebrow">Quote to cash</p>
      <h2>Sales</h2>
      <p>Prepare quotations and turn accepted offers into sales orders.</p>
    </div>
    <div class="pw-module-actions kontor-pagehead__actions">
      <?php if ($showCatalog): ?><a class="uk-button uk-button-default" href="<?= $e($adminUrl) ?>catalog/"><i class="fa fa-cube"></i> Catalog</a><?php endif; ?>
      <?php if ($showInvoices): ?><a class="uk-button uk-button-default" href="<?= $e($adminUrl) ?>invoices/"><i class="fa fa-file-text"></i> Invoices</a><?php endif; ?>
      <?php if ($canCreateQuotation): ?><a class="uk-button uk-button-primary" href="<?= $e($adminUrl) ?>sales-quotation/"><i class="fa fa-plus"></i> New quotation</a><?php endif; ?>
    </div>
  </header>

  <section class="uk-card uk-card-default uk-card-small uk-card-body uk-margin-medium-bottom">
    <div class="uk-grid-medium uk-flex-middle" uk-grid>
      <div class="uk-width-1-1 uk-width-1-3@l">
        <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Sales flow</p>
        <h3 class="uk-card-title uk-margin-small-top">From offer to order</h3>
        <p class="uk-text-muted uk-margin-remove-bottom">Draft an offer, send it for a customer decision, then convert accepted work into an order. Invoicing continues from the order when that component is available.</p>
      </div>
      <div class="uk-width-1-1 uk-width-2-3@l">
        <div class="uk-grid-small uk-grid-divider uk-child-width-1-2 uk-child-width-1-4@l" uk-grid>
          <div><div class="uk-text-meta">Draft offers</div><div class="uk-text-large"><strong><?= $e((string) $draftQuotations) ?></strong></div><div class="uk-text-meta">Still being prepared</div></div>
          <div><div class="uk-text-meta">Awaiting decision</div><div class="uk-text-large"><strong><?= $e((string) $openQuotations) ?></strong></div><div class="uk-text-meta">Issued or sent</div></div>
          <div><div class="uk-text-meta">Accepted</div><div class="uk-text-large"><strong><?= $e((string) $acceptedQuotations) ?></strong></div><div class="uk-text-meta">Ready for ordering</div></div>
          <?php if ($canViewOrders): ?><div><div class="uk-text-meta">Open orders</div><div class="uk-text-large"><strong><?= $e((string) $openOrders) ?></strong></div><div class="uk-text-meta">Pending or confirmed</div></div><?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="uk-card uk-card-default uk-card-small uk-card-body uk-margin-medium-bottom">
    <header class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-grid-small" uk-grid>
      <div><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Customer offers</p><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">Quotations</h3><p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom">Prepare pricing, capture the customer decision and convert accepted work.</p></div>
      <div><span class="uk-label"><?= $e((string) count($quotations)) ?> total</span></div>
    </header>
    <?php if ($quotations !== []): ?>
      <div class="uk-overflow-auto uk-visible@m uk-margin-top">
      <table class="uk-table uk-table-divider uk-table-middle uk-table-small uk-margin-remove-bottom">
        <thead><tr><th>Quotation</th><th>Customer</th><th>Valid until</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($quotations as $quotation): ?>
            <tr>
              <td><a href="<?= $e($adminUrl) ?>sales-quotation/?id=<?= $e(rawurlencode($quotation->uid->toString())) ?>"><strong><?= $e($quotation->number ?? 'Draft quotation') ?></strong></a></td>
              <td><?= $e($customer($quotation->customerType, $quotation->customerUid)) ?></td>
              <td><?= $e($quotation->validUntil?->format('M j, Y') ?? 'No expiry') ?></td>
              <td><?= $e($money($quotation->total)) ?></td>
              <td><span class="uk-label<?= $statusClass($quotation->status) ?>"><?= $e($humanize($quotation->status)) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <ul class="uk-list uk-list-divider uk-hidden@m uk-margin-top uk-margin-remove-bottom">
        <?php foreach ($quotations as $quotation): ?>
          <li>
            <div class="uk-flex uk-flex-between uk-flex-middle uk-grid-small" uk-grid><div><a class="uk-link-reset" href="<?= $e($adminUrl) ?>sales-quotation/?id=<?= $e(rawurlencode($quotation->uid->toString())) ?>"><strong><?= $e($quotation->number ?? 'Draft quotation') ?></strong></a></div><div><span class="uk-label<?= $statusClass($quotation->status) ?>"><?= $e($humanize($quotation->status)) ?></span></div></div>
            <div class="uk-text-muted uk-margin-small-top"><?= $e($customer($quotation->customerType, $quotation->customerUid)) ?></div>
            <div class="uk-grid-small uk-child-width-1-2 uk-margin-small-top" uk-grid><div><span class="uk-text-meta">Total</span><br><strong><?= $e($money($quotation->total)) ?></strong></div><div><span class="uk-text-meta">Valid until</span><br><strong><?= $e($quotation->validUntil?->format('M j, Y') ?? 'No expiry') ?></strong></div></div>
            <a class="uk-button uk-button-default uk-width-1-1 uk-margin-small-top uk-link-reset" href="<?= $e($adminUrl) ?>sales-quotation/?id=<?= $e(rawurlencode($quotation->uid->toString())) ?>">Open quotation</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="uk-placeholder uk-text-center uk-margin-top"><span class="fa fa-file-text-o fa-2x uk-text-muted"></span><h3>No quotations yet</h3><p class="uk-text-muted">Create a customer offer to start the sales flow.</p><?php if ($canCreateQuotation): ?><a class="uk-button uk-button-primary" href="<?= $e($adminUrl) ?>sales-quotation/"><i class="fa fa-plus"></i> Create quotation</a><?php endif; ?></div>
    <?php endif; ?>
  </section>

  <?php if ($canViewOrders): ?>
  <section class="uk-card uk-card-default uk-card-small uk-card-body">
    <header class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-grid-small" uk-grid>
      <div><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Fulfillment handoff</p><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">Sales orders</h3><p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom">Track customer commitment, payment and fulfillment after an offer is accepted.</p></div>
      <div><span class="uk-label"><?= $e((string) count($orders)) ?> total</span></div>
    </header>
    <?php if ($orders !== []): ?>
      <div class="uk-overflow-auto uk-visible@m uk-margin-top">
      <table class="uk-table uk-table-divider uk-table-middle uk-table-small uk-margin-remove-bottom">
        <thead><tr><th>Order</th><th>Customer</th><th>Delivery</th><th>Total</th><th>Progress</th></tr></thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
            <tr>
              <td><a href="<?= $e($adminUrl) ?>sales-order/?id=<?= $e(rawurlencode($order->uid->toString())) ?>"><strong><?= $e($order->number ?? 'Pending order') ?></strong></a></td>
              <td><?= $e($customer($order->customerType, $order->customerUid)) ?></td>
              <td><?= $e($order->expectedDeliveryDate?->format('M j, Y') ?? 'Not scheduled') ?></td>
              <td><?= $e($money($order->total)) ?></td>
              <td><span class="uk-label<?= $statusClass($order->orderStatus) ?>"><?= $e($humanize($order->orderStatus)) ?></span><div class="uk-text-meta uk-margin-small-top"><?= $e($humanize($order->paymentStatus)) ?> · <?= $e($humanize($order->fulfillmentStatus)) ?></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <ul class="uk-list uk-list-divider uk-hidden@m uk-margin-top uk-margin-remove-bottom">
        <?php foreach ($orders as $order): ?>
          <li>
            <div class="uk-flex uk-flex-between uk-flex-middle uk-grid-small" uk-grid><div><a class="uk-link-reset" href="<?= $e($adminUrl) ?>sales-order/?id=<?= $e(rawurlencode($order->uid->toString())) ?>"><strong><?= $e($order->number ?? 'Pending order') ?></strong></a></div><div><span class="uk-label<?= $statusClass($order->orderStatus) ?>"><?= $e($humanize($order->orderStatus)) ?></span></div></div>
            <div class="uk-text-muted uk-margin-small-top"><?= $e($customer($order->customerType, $order->customerUid)) ?></div>
            <div class="uk-grid-small uk-child-width-1-2 uk-margin-small-top" uk-grid><div><span class="uk-text-meta">Total</span><br><strong><?= $e($money($order->total)) ?></strong></div><div><span class="uk-text-meta">Delivery</span><br><strong><?= $e($order->expectedDeliveryDate?->format('M j, Y') ?? 'Not scheduled') ?></strong></div></div>
            <div class="uk-text-meta uk-margin-small-top"><?= $e($humanize($order->paymentStatus)) ?> payment · <?= $e($humanize($order->fulfillmentStatus)) ?> fulfillment</div>
            <a class="uk-button uk-button-default uk-width-1-1 uk-margin-small-top uk-link-reset" href="<?= $e($adminUrl) ?>sales-order/?id=<?= $e(rawurlencode($order->uid->toString())) ?>">Open order</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="uk-placeholder uk-text-center uk-margin-top"><span class="fa fa-shopping-cart fa-2x uk-text-muted"></span><h3>No sales orders yet</h3><p class="uk-text-muted">Open an accepted quotation and convert it when the customer is ready to proceed.</p></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>

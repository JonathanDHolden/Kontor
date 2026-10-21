<?php

/** @var \Kontor\Automation\Domain\AutomationRule[] $rules */
/** @var \Kontor\Automation\Domain\AutomationRule[] $allRules */
/** @var \Kontor\Automation\Domain\ExecutionLog[] $logs */
/** @var array<string, array{conditions: int, actions: int, executions: int, lastLog: \Kontor\Automation\Domain\ExecutionLog|null}> $ruleSummaries */
/** @var array<string, string> $ruleLabels */
/** @var string $query */
/** @var string $selectedStatus */
/** @var bool $canManage */
/** @var bool $canViewLogs */
/** @var string $adminUrl */
/** @var callable $e */

$humanize = static fn (string $value): string =>
    ucwords(str_replace(['.', '_', '-'], ' ', $value));
$statusClass = static fn (string $status): string => match ($status) {
    'active' => ' uk-label-success',
    'paused' => ' uk-label-warning',
    default => '',
};
$logOutcome = static function ($log): array {
    if ($log->error !== null) {
        return ['Failed', 'uk-label-danger', 'The run stopped with an error.'];
    }
    if ($log->recursionBlocked) {
        return ['Blocked', 'uk-label-warning', 'A repeated event was safely prevented.'];
    }
    if (!$log->matched) {
        return ['Skipped', '', 'The event did not meet the rule conditions.'];
    }

    return ['Completed', 'uk-label-success', count($log->actionsResult) . ' action' . (count($log->actionsResult) === 1 ? '' : 's') . ' completed.'];
};
$activeCount = count(array_filter($allRules, static fn ($rule): bool => $rule->status === 'active'));
$pausedCount = count(array_filter($allRules, static fn ($rule): bool => $rule->status === 'paused'));
$failureCount = count(array_filter($logs, static fn ($log): bool => $log->error !== null || $log->recursionBlocked));
$completedCount = count(array_filter($logs, static fn ($log): bool => $log->matched && $log->error === null && !$log->recursionBlocked));
$filtersActive = $query !== '' || $selectedStatus !== '';
$statusUrl = static function (string $status) use ($adminUrl): string {
    return $adminUrl . 'automations/' . ($status !== '' ? '?' . http_build_query(['status' => $status]) : '');
};
?>
<div class="ProcessKontor pw-module-workspace kontor-shell">
  <header class="pw-module-head kontor-pagehead">
    <div><p class="kontor-eyebrow">Operations · Automation</p><h2>Automations</h2><p>Turn business events into consistent follow-up work, notifications and connected actions.</p></div>
    <?php if ($canManage): ?><div class="pw-module-actions kontor-pagehead__actions"><a class="uk-button uk-button-primary uk-link-reset" href="<?= $e($adminUrl) ?>automation/"><i class="fa fa-plus"></i> New automation</a></div><?php endif; ?>
  </header>

  <section class="uk-card uk-card-default uk-card-small uk-card-body uk-margin-medium-bottom">
    <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-grid-small" uk-grid><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">How it works</p><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">One event, a clear decision, useful follow-up</h3></div></div>
    <div class="uk-grid-small uk-grid-divider uk-child-width-1-1 uk-child-width-1-3@m uk-margin" uk-grid>
      <div><div class="uk-flex uk-flex-middle"><span class="kontor-stat__icon"><i class="fa fa-bell"></i></span><div><strong>1. When this happens</strong><div class="uk-text-meta uk-margin-small-top">Choose the business event that starts the automation.</div></div></div></div>
      <div><div class="uk-flex uk-flex-middle"><span class="kontor-stat__icon"><i class="fa fa-filter"></i></span><div><strong>2. Check the conditions</strong><div class="uk-text-meta uk-margin-small-top">Run only when the event data matches your criteria.</div></div></div></div>
      <div><div class="uk-flex uk-flex-middle"><span class="kontor-stat__icon"><i class="fa fa-bolt"></i></span><div><strong>3. Complete the actions</strong><div class="uk-text-meta uk-margin-small-top">Create work or notify the right people automatically.</div></div></div></div>
    </div>
    <p class="uk-text-muted uk-margin-remove-bottom">Example: when a high-priority request arrives, create a follow-up task and connect it to the original record.</p>
  </section>

  <div class="uk-grid-small uk-child-width-1-1 uk-child-width-1-2@s uk-child-width-1-4@l uk-margin-medium-bottom" uk-grid>
    <div><a class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat uk-link-reset<?= $selectedStatus === '' ? ' kontor-card--selected' : '' ?>" href="<?= $e($statusUrl('')) ?>"<?= $selectedStatus === '' ? ' aria-current="page"' : '' ?>><span class="kontor-stat__icon"><i class="fa fa-random"></i></span><span><strong class="kontor-stat__value"><?= $e((string) count($allRules)) ?></strong><span class="kontor-stat__label">All automations</span></span></a></div>
    <div><a class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat uk-link-reset<?= $selectedStatus === 'active' ? ' kontor-card--selected' : '' ?>" href="<?= $e($statusUrl('active')) ?>"<?= $selectedStatus === 'active' ? ' aria-current="page"' : '' ?>><span class="kontor-stat__icon kontor-stat__icon--success"><i class="fa fa-play"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $activeCount) ?></strong><span class="kontor-stat__label">Active</span></span></a></div>
    <div><a class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat uk-link-reset<?= $selectedStatus === 'paused' ? ' kontor-card--selected' : '' ?>" href="<?= $e($statusUrl('paused')) ?>"<?= $selectedStatus === 'paused' ? ' aria-current="page"' : '' ?>><span class="kontor-stat__icon"><i class="fa fa-pause"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $pausedCount) ?></strong><span class="kontor-stat__label">Paused</span></span></a></div>
    <?php if ($canViewLogs): ?><div><div class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat"><span class="kontor-stat__icon<?= $failureCount > 0 ? ' kontor-stat__icon--danger' : '' ?>"><i class="fa fa-check-circle"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $completedCount) ?></strong><span class="kontor-stat__label">Completed recently<?= $failureCount > 0 ? ' · ' . $e((string) $failureCount) . ' need attention' : '' ?></span></span></div></div><?php endif; ?>
  </div>

  <section class="uk-card uk-card-default uk-card-small uk-card-body">
    <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-grid-small" uk-grid><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Automation library</p><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom"><?= $e($selectedStatus === 'active' ? 'Active automations' : ($selectedStatus === 'paused' ? 'Paused automations' : 'All automations')) ?></h3><p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom"><?= $e((string) count($rules)) ?> rule<?= count($rules) === 1 ? '' : 's' ?> shown</p></div></div>

    <form class="uk-form-stacked uk-margin" method="get" action="<?= $e($adminUrl) ?>automations/">
      <div class="uk-grid-small uk-flex-bottom" uk-grid>
        <div class="uk-width-1-1 uk-width-expand@m"><label class="uk-form-label" for="automations-search">Search automations</label><div class="uk-inline uk-width-1-1 uk-margin-small-top"><span class="uk-form-icon" uk-icon="icon: search"></span><input class="uk-input" id="automations-search" type="search" name="q" value="<?= $e($query) ?>" placeholder="Name or business event"></div><div class="uk-text-meta uk-margin-small-top">Find a rule by its purpose or starting event.</div></div>
        <div class="uk-width-1-1 uk-width-1-4@m"><label class="uk-form-label" for="automations-status">Status</label><select class="uk-select uk-margin-small-top" id="automations-status" name="status"><option value="">All statuses</option><option value="active"<?= $selectedStatus === 'active' ? ' selected' : '' ?>>Active</option><option value="paused"<?= $selectedStatus === 'paused' ? ' selected' : '' ?>>Paused</option></select><div class="uk-text-meta uk-margin-small-top">Active rules respond to incoming events.</div></div>
        <div class="uk-width-1-1 uk-width-auto@m"><button class="uk-button uk-button-default uk-width-1-1" type="submit">Apply</button></div>
        <?php if ($filtersActive): ?><div class="uk-width-1-1 uk-width-auto@m"><a class="uk-button uk-button-text uk-width-1-1" href="<?= $e($adminUrl) ?>automations/">Reset</a></div><?php endif; ?>
      </div>
    </form>

    <?php if ($rules !== []): ?>
      <div class="uk-overflow-auto uk-visible@m"><table class="uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small uk-margin-remove-bottom"><thead><tr><th>Automation</th><th>Starts when</th><th>Workflow</th><th>Last run</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($rules as $rule): $uid = $rule->uid->toString(); $summary = $ruleSummaries[$uid]; $last = $summary['lastLog']; ?>
          <tr><td><a href="<?= $e($adminUrl) ?>automation/?id=<?= $e(rawurlencode($uid)) ?>"><strong><?= $e($rule->name) ?></strong><?php if ($canViewLogs): ?><div class="uk-text-meta uk-margin-small-top"><?= $e((string) $summary['executions']) ?> recorded run<?= $summary['executions'] === 1 ? '' : 's' ?></div><?php endif; ?></a></td><td><?= $e($humanize($rule->triggerEvent)) ?></td><td><strong><?= $e((string) $summary['conditions']) ?></strong> condition<?= $summary['conditions'] === 1 ? '' : 's' ?> · <strong><?= $e((string) $summary['actions']) ?></strong> action<?= $summary['actions'] === 1 ? '' : 's' ?></td><td><?= $canViewLogs ? ($last !== null ? $e($last->occurredAt->format('M j, Y · H:i')) : 'Never run') : 'Restricted' ?></td><td><span class="uk-label<?= $statusClass($rule->status) ?>"><?= $e($humanize($rule->status)) ?></span></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <div class="uk-hidden@m"><?php foreach ($rules as $rule): $uid = $rule->uid->toString(); $summary = $ruleSummaries[$uid]; $last = $summary['lastLog']; ?><a class="uk-card uk-card-default uk-card-small uk-card-body uk-display-block uk-link-reset uk-margin-small-bottom" href="<?= $e($adminUrl) ?>automation/?id=<?= $e(rawurlencode($uid)) ?>"><div class="uk-flex uk-flex-between uk-flex-top uk-grid-small" uk-grid><div class="uk-width-expand"><strong><?= $e($rule->name) ?></strong><div class="uk-text-meta uk-margin-small-top"><?= $e($humanize($rule->triggerEvent)) ?></div></div><div><span class="uk-label<?= $statusClass($rule->status) ?>"><?= $e($humanize($rule->status)) ?></span></div></div><div class="uk-grid-small uk-child-width-1-2 uk-margin-small-top" uk-grid><div><div class="uk-text-meta">Workflow</div><strong><?= $e((string) $summary['conditions']) ?> conditions · <?= $e((string) $summary['actions']) ?> actions</strong></div><div><div class="uk-text-meta">Last run</div><strong><?= $canViewLogs ? ($last !== null ? $e($last->occurredAt->format('M j · H:i')) : 'Never') : 'Restricted' ?></strong></div></div></a><?php endforeach; ?></div>
    <?php else: ?>
      <div class="uk-placeholder uk-text-center"><span class="fa fa-bolt fa-2x uk-text-muted"></span><h3 class="uk-margin-small-top uk-margin-small-bottom"><?= $filtersActive ? 'No matching automations' : 'Create your first automation' ?></h3><p class="uk-text-muted uk-margin-small-top"><?= $filtersActive ? 'Try a broader search or reset the filters.' : 'Choose a business event, add optional conditions and decide what Kontor should do next.' ?></p><?php if ($filtersActive): ?><a class="uk-button uk-button-default uk-link-reset" href="<?= $e($adminUrl) ?>automations/">Reset filters</a><?php elseif ($canManage): ?><a class="uk-button uk-button-primary uk-link-reset" href="<?= $e($adminUrl) ?>automation/"><i class="fa fa-plus"></i> Create automation</a><?php endif; ?></div>
    <?php endif; ?>
  </section>

  <?php if ($canViewLogs): ?><section class="uk-card uk-card-default uk-card-small uk-card-body uk-margin-medium-top">
    <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-grid-small" uk-grid><div><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Activity</p><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">Recent runs</h3><p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom">Review what ran, what was skipped and anything that needs attention.</p></div></div>
    <?php if ($logs !== []): ?>
      <div class="uk-overflow-auto uk-visible@m uk-margin-top"><table class="uk-table uk-table-divider uk-table-middle uk-table-small uk-margin-remove-bottom"><thead><tr><th>When</th><th>Automation</th><th>Event</th><th>Mode</th><th>Outcome</th><th>Details</th></tr></thead><tbody><?php foreach ($logs as $log): $outcome = $logOutcome($log); ?><tr><td><?= $e($log->occurredAt->format('M j, Y · H:i')) ?></td><td><?= $e($log->ruleUid !== null ? ($ruleLabels[$log->ruleUid] ?? 'Former automation') : 'Unassigned event') ?></td><td><?= $e($humanize($log->triggerEvent)) ?></td><td><?= $e($log->dryRun ? 'Test' : 'Live') ?></td><td><span class="uk-label <?= $e($outcome[1]) ?>"><?= $e($outcome[0]) ?></span></td><td><?= $e($log->error ?? $outcome[2]) ?></td></tr><?php endforeach; ?></tbody></table></div>
      <ul class="uk-list uk-list-divider uk-hidden@m uk-margin-top uk-margin-remove-bottom"><?php foreach ($logs as $log): $outcome = $logOutcome($log); ?><li><div class="uk-flex uk-flex-between uk-flex-top"><strong><?= $e($log->ruleUid !== null ? ($ruleLabels[$log->ruleUid] ?? 'Former automation') : 'Unassigned event') ?></strong><span class="uk-label <?= $e($outcome[1]) ?>"><?= $e($outcome[0]) ?></span></div><div class="uk-text-meta uk-margin-small-top"><?= $e($log->occurredAt->format('M j, Y · H:i')) ?> · <?= $e($log->dryRun ? 'Test' : 'Live') ?> · <?= $e($humanize($log->triggerEvent)) ?></div><p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom"><?= $e($log->error ?? $outcome[2]) ?></p></li><?php endforeach; ?></ul>
    <?php else: ?><div class="uk-placeholder uk-text-center uk-margin-top"><span class="fa fa-history fa-2x uk-text-muted"></span><h3 class="uk-margin-small-top uk-margin-small-bottom">No runs yet</h3><p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom"><?= $allRules === [] ? 'Execution history will appear after your first automation evaluates an event.' : 'The next event evaluation will appear here with its outcome.' ?></p></div><?php endif; ?>
  </section><?php endif; ?>
</div>

<?php

/** @var \Kontor\Tasks\Domain\Task[] $tasks */
/** @var \Kontor\Tasks\Domain\Task[] $allTasks */
/** @var string $query */
/** @var string|null $selectedStatus */
/** @var string|null $selectedPriority */
/** @var string $selectedScope */
/** @var bool $archived */
/** @var array<int, string> $assigneeLabels */
/** @var int $currentUserId */
/** @var bool $canCreate */
/** @var string $adminUrl */
/** @var callable $e */

$today = new DateTimeImmutable('today');
$tomorrow = $today->modify('+1 day');
$nextWeek = $today->modify('+7 days');
$allCount = count($allTasks);
$activeCount = count(array_filter($allTasks, static fn ($task): bool => $task->isOpen()));
$overdueCount = count(array_filter($allTasks, static fn ($task): bool => $task->isOverdue()));
$todayCount = count(array_filter(
    $allTasks,
    static fn ($task): bool => $task->isOpen() && $task->dueAt !== null
        && $task->dueAt >= $today && $task->dueAt < $tomorrow
));
$mineCount = count(array_filter(
    $allTasks,
    static fn ($task): bool => $task->isOpen() && $task->assignedTo === $currentUserId
));
$humanize = static fn (?string $value): string => $value === null || $value === ''
    ? 'Not set'
    : ucwords(str_replace('_', ' ', $value));
$description = static function (?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return 'No description added';
    }

    return mb_strimwidth($value, 0, 150, '…');
};
$duePresentation = static function ($task) use ($today, $tomorrow, $nextWeek): array {
    if ($task->dueAt === null) {
        return ['label' => 'No due date', 'meta' => 'Unscheduled', 'class' => ''];
    }
    if ($task->isOverdue()) {
        return [
            'label' => $task->dueAt->format('M j · H:i'),
            'meta' => 'Overdue',
            'class' => 'uk-text-danger',
        ];
    }
    if ($task->dueAt >= $today && $task->dueAt < $tomorrow) {
        return ['label' => $task->dueAt->format('H:i'), 'meta' => 'Due today', 'class' => ''];
    }
    if ($task->dueAt >= $tomorrow && $task->dueAt < $nextWeek) {
        return [
            'label' => $task->dueAt->format('D · H:i'),
            'meta' => 'This week',
            'class' => '',
        ];
    }

    return [
        'label' => $task->dueAt->format('M j, Y · H:i'),
        'meta' => $task->status === 'done' ? 'Completed task' : 'Scheduled',
        'class' => '',
    ];
};
$statusClass = static fn (string $status): string => match ($status) {
    'done' => ' uk-label-success',
    'cancelled' => ' uk-label-warning',
    default => '',
};
$priorityIcon = static fn (string $priority): string => match ($priority) {
    'urgent' => 'exclamation-circle',
    'high' => 'arrow-up',
    'low' => 'arrow-down',
    default => 'minus',
};
$priorityClass = static fn (string $priority): string => match ($priority) {
    'urgent' => 'uk-text-danger',
    'high' => 'uk-text-warning',
    default => '',
};
$filtersActive = $query !== '' || $selectedStatus !== null || $selectedPriority !== null;
$scopeUrl = static function (string $scope) use ($adminUrl, $archived): string {
    $parameters = ['scope' => $scope];
    if ($archived) {
        $parameters['archived'] = '1';
    }

    return $adminUrl . 'tasks/?' . http_build_query($parameters);
};
?>
<div class="ProcessKontor pw-module-workspace kontor-shell">
  <header class="pw-module-head kontor-pagehead">
    <div>
      <p class="kontor-eyebrow"><?= $archived ? 'Task history' : 'Daily work inbox' ?></p>
      <h2><?= $archived ? 'Archived tasks' : 'Tasks' ?></h2>
      <p><?= $archived
          ? 'Review completed history and restore work that needs to return to the active queue.'
          : 'See what needs attention, choose the next action and keep work moving across Kontor.' ?></p>
    </div>
    <div class="pw-module-actions kontor-pagehead__actions">
      <a class="uk-button uk-button-default" href="<?= $e($adminUrl) ?>tasks/?archived=<?= $archived ? '0' : '1' ?>"><i class="fa fa-<?= $archived ? 'check-square-o' : 'archive' ?>"></i> <?= $archived ? 'Active tasks' : 'Archive' ?></a>
      <?php if ($canCreate && !$archived): ?><a class="uk-button uk-button-primary" href="<?= $e($adminUrl) ?>task/"><i class="fa fa-plus"></i> New task</a><?php endif; ?>
    </div>
  </header>

  <?php if (!$archived): ?>
    <div class="uk-grid-small uk-child-width-1-2 uk-child-width-1-4@l uk-margin-medium-bottom" uk-grid>
      <div><a class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat uk-link-reset<?= $selectedScope === 'active' ? ' kontor-card--selected' : '' ?>" href="<?= $e($scopeUrl('active')) ?>"<?= $selectedScope === 'active' ? ' aria-current="page"' : '' ?>><span class="kontor-stat__icon uk-visible@s"><i class="fa fa-play-circle-o"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $activeCount) ?></strong><span class="kontor-stat__label">Active work</span></span></a></div>
      <div><a class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat uk-link-reset<?= $selectedScope === 'mine' ? ' kontor-card--selected' : '' ?>" href="<?= $e($scopeUrl('mine')) ?>"<?= $selectedScope === 'mine' ? ' aria-current="page"' : '' ?>><span class="kontor-stat__icon uk-visible@s"><i class="fa fa-user"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $mineCount) ?></strong><span class="kontor-stat__label">Assigned to me</span></span></a></div>
      <div><a class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat uk-link-reset<?= $selectedScope === 'today' ? ' kontor-card--selected' : '' ?>" href="<?= $e($scopeUrl('today')) ?>"<?= $selectedScope === 'today' ? ' aria-current="page"' : '' ?>><span class="kontor-stat__icon uk-visible@s"><i class="fa fa-calendar-check-o"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $todayCount) ?></strong><span class="kontor-stat__label">Due today</span></span></a></div>
      <div><a class="uk-card uk-card-default uk-card-small uk-card-body kontor-stat uk-link-reset<?= $selectedScope === 'overdue' ? ' kontor-card--selected' : '' ?>" href="<?= $e($scopeUrl('overdue')) ?>"<?= $selectedScope === 'overdue' ? ' aria-current="page"' : '' ?>><span class="kontor-stat__icon uk-visible@s<?= $overdueCount > 0 ? ' kontor-stat__icon--danger' : '' ?>"><i class="fa fa-exclamation-triangle"></i></span><span><strong class="kontor-stat__value"><?= $e((string) $overdueCount) ?></strong><span class="kontor-stat__label">Overdue</span></span></a></div>
    </div>
  <?php endif; ?>

  <section class="uk-card uk-card-default uk-card-small uk-card-body">
    <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-grid-small" uk-grid>
      <div>
        <p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom"><?= $archived ? 'History' : 'Work queue' ?></p>
        <h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom"><?= $e(match ($selectedScope) { 'active' => 'Active tasks', 'mine' => 'Assigned to me', 'overdue' => 'Overdue tasks', 'today' => 'Due today', 'upcoming' => 'Coming up', default => $archived ? 'Archived tasks' : 'All tasks' }) ?></h3>
        <p class="uk-text-muted uk-margin-small-top uk-margin-remove-bottom"><?= $e((string) count($tasks)) ?> task<?= count($tasks) === 1 ? '' : 's' ?> shown</p>
      </div>
      <?php if (!$archived): ?><div class="uk-flex uk-flex-wrap uk-grid-small" uk-grid><div><a class="uk-button uk-link-reset <?= $selectedScope === 'all' ? 'uk-button-primary' : 'uk-button-default' ?>" href="<?= $e($scopeUrl('all')) ?>"><i class="fa fa-list-ul"></i> All <?= $e((string) $allCount) ?></a></div><div><a class="uk-button uk-link-reset <?= $selectedScope === 'upcoming' ? 'uk-button-primary' : 'uk-button-default' ?>" href="<?= $e($scopeUrl('upcoming')) ?>"><i class="fa fa-calendar"></i> Coming up</a></div></div><?php endif; ?>
    </div>

    <div class="uk-alert uk-alert-primary uk-margin" role="note">
      <div class="uk-flex uk-flex-top"><i class="fa fa-info-circle uk-margin-small-right uk-margin-small-top"></i><div><strong>How this workspace works</strong><div class="uk-text-small uk-margin-small-top">Create and assign the task, set a useful priority and due date, move it into progress, then complete and archive it when the work is finished.</div></div></div>
    </div>

    <form class="uk-form-stacked uk-margin" method="get" action="<?= $e($adminUrl) ?>tasks/">
      <input type="hidden" name="scope" value="<?= $e($selectedScope) ?>">
      <?php if ($archived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
      <div class="uk-grid-small uk-flex-bottom" uk-grid>
        <div class="uk-width-1-1 uk-width-expand@m"><label class="uk-form-label" for="tasks-search">Search tasks</label><div class="uk-inline uk-width-1-1 uk-margin-small-top"><span class="uk-form-icon" uk-icon="icon: search"></span><input class="uk-input" id="tasks-search" type="search" name="q" value="<?= $e($query) ?>" placeholder="Search title or description"></div><div class="uk-text-meta uk-margin-small-top">Matches words in the task title and description.</div></div>
        <div class="uk-width-1-1 uk-width-1-5@m"><label class="uk-form-label" for="tasks-status">Status</label><select class="uk-select uk-margin-small-top" id="tasks-status" name="status"><option value="">All statuses</option><?php foreach (['open' => 'Open', 'in_progress' => 'In progress', 'done' => 'Done', 'cancelled' => 'Cancelled'] as $value => $label): ?><option value="<?= $e($value) ?>"<?= $selectedStatus === $value ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select><div class="uk-text-meta uk-margin-small-top">Choose the current stage of work.</div></div>
        <div class="uk-width-1-1 uk-width-1-5@m"><label class="uk-form-label" for="tasks-priority">Priority</label><select class="uk-select uk-margin-small-top" id="tasks-priority" name="priority"><option value="">All priorities</option><?php foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $value => $label): ?><option value="<?= $e($value) ?>"<?= $selectedPriority === $value ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select><div class="uk-text-meta uk-margin-small-top">Focus on the level of urgency.</div></div>
        <div class="uk-width-1-1 uk-width-auto@m"><button class="uk-button uk-button-default uk-width-1-1" type="submit">Apply</button></div>
        <?php if ($filtersActive): ?><div class="uk-width-1-1 uk-width-auto@m"><a class="uk-button uk-button-default uk-width-1-1" href="<?= $e($scopeUrl($selectedScope)) ?>">Reset</a></div><?php endif; ?>
      </div>
    </form>

    <?php if ($tasks !== []): ?>
      <div class="uk-overflow-auto uk-visible@m">
        <table class="uk-table uk-table-divider uk-table-hover uk-table-middle uk-table-small">
          <thead><tr><th>Task</th><th>Owner</th><th>Due</th><th>Priority</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($tasks as $task): $due = $duePresentation($task); $taskUrl = $adminUrl . 'task/?id=' . rawurlencode($task->uid->toString()) . ($archived ? '&archived=1' : ''); ?><tr>
            <td><a href="<?= $e($taskUrl) ?>"><strong><?= $e($task->title) ?></strong></a><div class="uk-text-meta uk-margin-small-top"><?= $e($description($task->description)) ?><?= $task->recurrenceRule !== null ? ' · Repeats ' . $e($humanize($task->recurrenceRule)) : '' ?></div></td>
            <td><?= $e($task->assignedTo !== null ? ($assigneeLabels[$task->assignedTo] ?? 'Former user') : 'Unassigned') ?></td>
            <td class="<?= $e($due['class']) ?>"><strong><?= $e($due['label']) ?></strong><div class="uk-text-meta"><?= $e($due['meta']) ?></div></td>
            <td><span class="uk-text-nowrap <?= $e($priorityClass($task->priority)) ?>"><i class="fa fa-<?= $e($priorityIcon($task->priority)) ?>"></i> <?= $e($humanize($task->priority)) ?></span></td>
            <td><span class="uk-label<?= $statusClass($task->status) ?>"><?= $e($humanize($task->status)) ?></span></td>
          </tr><?php endforeach; ?></tbody>
        </table>
      </div>
      <div class="uk-hidden@m">
        <?php foreach ($tasks as $task): $due = $duePresentation($task); $taskUrl = $adminUrl . 'task/?id=' . rawurlencode($task->uid->toString()) . ($archived ? '&archived=1' : ''); ?>
          <a class="uk-card uk-card-default uk-card-small uk-card-body uk-display-block uk-link-reset uk-margin-small-bottom" href="<?= $e($taskUrl) ?>">
            <div class="uk-flex uk-flex-between uk-flex-top uk-grid-small" uk-grid><div class="uk-width-expand"><strong><?= $e($task->title) ?></strong><div class="uk-text-meta uk-margin-small-top"><?= $e($description($task->description)) ?></div></div><div><span class="uk-label<?= $statusClass($task->status) ?>"><?= $e($humanize($task->status)) ?></span></div></div>
            <div class="uk-grid-small uk-child-width-1-2 uk-margin-small-top" uk-grid><div><div class="uk-text-meta">Due</div><strong class="<?= $e($due['class']) ?>"><?= $e($due['label']) ?></strong></div><div><div class="uk-text-meta">Owner</div><strong><?= $e($task->assignedTo !== null ? ($assigneeLabels[$task->assignedTo] ?? 'Former user') : 'Unassigned') ?></strong></div></div>
            <div class="uk-text-small uk-margin-small-top <?= $e($priorityClass($task->priority)) ?>"><i class="fa fa-<?= $e($priorityIcon($task->priority)) ?>"></i> <?= $e($humanize($task->priority)) ?><?= $task->recurrenceRule !== null ? ' · Repeats ' . $e($humanize($task->recurrenceRule)) : '' ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="uk-placeholder uk-text-center">
        <span class="fa fa-check-circle-o fa-2x uk-text-muted"></span>
        <h3 class="uk-margin-small-top uk-margin-small-bottom"><?= $filtersActive ? 'No matching tasks' : match ($selectedScope) { 'active' => 'No active tasks', 'mine' => 'Nothing assigned to you', 'overdue' => 'Nothing overdue', 'today' => 'Nothing due today', 'upcoming' => 'Nothing coming up', default => $archived ? 'Archive is empty' : 'No tasks yet' } ?></h3>
        <p class="uk-text-muted uk-margin-small-top"><?= $filtersActive ? 'Try a broader search or reset the filters.' : ($selectedScope === 'all' ? 'Create a task to add work to this queue.' : 'Choose another focus view to see more work.') ?></p>
        <?php if ($filtersActive): ?><a class="uk-button uk-button-default" href="<?= $e($scopeUrl($selectedScope)) ?>">Reset filters</a><?php elseif ($canCreate && !$archived && $selectedScope === 'all'): ?><a class="uk-button uk-button-primary" href="<?= $e($adminUrl) ?>task/"><i class="fa fa-plus"></i> Create first task</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php

/** @var string $query */
/** @var \Kontor\SDK\DTO\SearchResult|null $result */
/** @var string $selectedEntityType */
/** @var array<string, string> $availableEntityTypes */
/** @var int $page */
/** @var int $totalPages */
/** @var string $adminUrl */
/** @var callable $e */

$presentations = [
    'contact' => ['route' => 'contact', 'icon' => 'user', 'label' => 'Contact'],
    'company' => ['route' => 'company', 'icon' => 'building-o', 'label' => 'Company'],
    'lead' => ['route' => 'crm-lead', 'icon' => 'bullseye', 'label' => 'Lead'],
    'deal' => ['route' => 'crm-deal', 'icon' => 'handshake-o', 'label' => 'Deal'],
    'catalog_item' => ['route' => 'catalog-item', 'icon' => 'cube', 'label' => 'Catalog item'],
    'component' => ['route' => 'components', 'icon' => 'cubes', 'label' => 'Component'],
];
$scopeLabel = $selectedEntityType === ''
    ? 'all available workspaces'
    : strtolower($availableEntityTypes[$selectedEntityType] ?? 'available records');
$pageUrl = static function (int $targetPage) use ($query, $selectedEntityType): string {
    return './?' . http_build_query(array_filter([
        'q' => $query,
        'type' => $selectedEntityType,
        'page' => $targetPage > 1 ? $targetPage : '',
    ], static fn (string|int $value): bool => $value !== ''));
};
$scopeUrl = static fn (string $entityType): string => './?' . http_build_query([
    'q' => $query,
    'type' => $entityType,
]);
$resultUrl = static function (\Kontor\SDK\DTO\SearchHit $hit) use ($adminUrl, $presentations): string {
    if ($hit->url !== null && $hit->url !== '') {
        return $adminUrl . ltrim($hit->url, '/');
    }
    if ($hit->entityType === 'component') {
        return $adminUrl . 'components/?q=' . rawurlencode($hit->entityUid);
    }
    $route = $presentations[$hit->entityType]['route'] ?? '';

    return $route !== ''
        ? $adminUrl . $route . '/?id=' . rawurlencode($hit->entityUid)
        : $adminUrl;
};
$humanize = static fn (string $value): string => ucwords(str_replace(['_', '-'], ' ', $value));
$displayTitle = static function (\Kontor\SDK\DTO\SearchHit $hit): string {
    if ($hit->entityType !== 'component') {
        return $hit->title;
    }

    $name = preg_replace('/^Kontor/', '', $hit->title) ?? $hit->title;
    $name = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $name) ?? $name;
    $acronyms = ['crm' => 'CRM', 'api' => 'API', 'ai' => 'AI', 'graphql' => 'GraphQL'];
    $name = preg_replace_callback(
        '/\b(crm|api|ai|graphql)\b/i',
        static fn (array $matches): string => $acronyms[strtolower($matches[1])],
        $name
    ) ?? $name;
    $name = ucfirst(trim($name));

    return trim('Kontor ' . ($name !== '' ? $name : 'Core'));
};
$hasInput = $query !== '' || $selectedEntityType !== '';
$queryReady = mb_strlen($query) >= 2;
?>
<div class="ProcessKontor pw-module-workspace kontor-shell">
  <header class="pw-module-head kontor-pagehead">
    <div><p class="kontor-eyebrow">Navigation · Global search</p><h2>Search</h2><p>Find customers, opportunities, catalog items and installed workspaces from one place.</p></div>
  </header>

  <section class="uk-card uk-card-default uk-card-small uk-card-body uk-margin-medium-bottom">
    <div class="uk-flex uk-flex-between uk-flex-top uk-flex-wrap uk-grid-small" uk-grid><div class="uk-width-expand@m"><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Search Kontor</p><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom">What are you looking for?</h3><p class="uk-text-muted uk-margin-small-top">Search by a name, email, company, opportunity, SKU, barcode or component title.</p></div><div><span class="uk-label"><?= $e((string) count($availableEntityTypes)) ?> workspace<?= count($availableEntityTypes) === 1 ? '' : 's' ?></span></div></div>
    <form class="uk-form-stacked uk-margin-medium-top" method="get" action="./"><div class="uk-grid-small uk-flex-bottom" uk-grid><div class="uk-width-1-1 uk-width-expand@m"><label class="uk-form-label" for="global-search-query">Search terms</label><div class="uk-inline uk-width-1-1 uk-margin-small-top"><span class="uk-form-icon"><i class="fa fa-search"></i></span><input class="uk-input" id="global-search-query" name="q" type="search" minlength="2" value="<?= $e($query) ?>" placeholder="Customer, deal, SKU or workspace" autocomplete="off" autofocus required></div><div class="uk-text-meta uk-margin-small-top">Enter at least two characters. More specific terms produce better matches.</div></div><div class="uk-width-1-1 uk-width-1-3@m"><label class="uk-form-label" for="global-search-type">Search within</label><select class="uk-select uk-margin-small-top" id="global-search-type" name="type"><option value="">Every available workspace</option><?php foreach ($availableEntityTypes as $type => $label): ?><option value="<?= $e($type) ?>"<?= $selectedEntityType === $type ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select><div class="uk-text-meta uk-margin-small-top">Narrow the scope only when the result set is too broad.</div></div><div class="uk-width-1-1 uk-width-auto@m"><button class="uk-button uk-button-primary uk-width-1-1" type="submit">Search</button></div><?php if ($hasInput): ?><div class="uk-width-1-1 uk-width-auto@m"><a class="uk-button uk-button-default uk-link-reset uk-width-1-1" href="./">Clear</a></div><?php endif; ?></div></form>
  </section>

  <?php if ($result !== null): ?>
    <div class="uk-grid-medium" uk-grid>
      <div class="uk-width-1-1 uk-width-2-3@l">
        <section class="uk-card uk-card-default uk-card-small uk-card-body">
          <div class="uk-flex uk-flex-between uk-flex-top uk-flex-wrap uk-grid-small" uk-grid><div class="uk-width-expand@m"><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Search results</p><h3 class="uk-card-title uk-margin-small-top uk-margin-remove-bottom"><?= $e((string) $result->total) ?> match<?= $result->total === 1 ? '' : 'es' ?> for “<?= $e($query) ?>”</h3><p class="uk-text-muted uk-margin-small-top">Searching <?= $e($scopeLabel) ?><?= $totalPages > 1 ? ' · Page ' . $e((string) $page) . ' of ' . $e((string) $totalPages) : '' ?>.</p></div><?php if ($selectedEntityType !== ''): ?><div><a class="uk-button uk-button-default uk-link-reset" href="./?q=<?= $e(rawurlencode($query)) ?>"><i class="fa fa-list"></i> All workspaces</a></div><?php endif; ?></div>

          <?php if ($result->hits !== []): ?><ul class="uk-list uk-list-divider uk-margin-medium-top"><?php foreach ($result->hits as $hit): ?><?php $presentation = $presentations[$hit->entityType] ?? ['route' => '', 'icon' => 'file-o', 'label' => $humanize($hit->entityType)]; ?><li><div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-grid-small" uk-grid><div class="uk-width-expand@m"><div class="uk-flex uk-flex-top"><span class="kontor-stat__icon uk-margin-small-right"><i class="fa fa-<?= $e($presentation['icon']) ?>"></i></span><span><a href="<?= $e($resultUrl($hit)) ?>"><strong><?= $e($displayTitle($hit)) ?></strong></a><?php if ($hit->subtitle !== null && $hit->subtitle !== ''): ?><span class="uk-text-meta uk-display-block uk-margin-small-top"><?= $e($humanize($hit->subtitle)) ?></span><?php endif; ?></span></div></div><div class="uk-flex uk-flex-middle uk-grid-small" uk-grid><div><a class="uk-label uk-link-reset" href="<?= $e($scopeUrl($hit->entityType)) ?>"<?= $selectedEntityType === $hit->entityType ? ' aria-current="page"' : '' ?>><?= $e($presentation['label']) ?></a></div></div></div></li><?php endforeach; ?></ul>
          <?php else: ?><div class="uk-placeholder uk-text-center uk-margin-medium-top"><i class="fa fa-search fa-2x uk-text-muted"></i><h4>No matching records</h4><p class="uk-text-muted">Check the spelling, use a broader term or search every available workspace.</p><?php if ($selectedEntityType !== ''): ?><a class="uk-button uk-button-default uk-link-reset" href="./?q=<?= $e(rawurlencode($query)) ?>">Search all workspaces</a><?php endif; ?></div><?php endif; ?>
        </section>

        <?php if ($totalPages > 1): ?><nav class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-grid-small uk-margin-medium-top" aria-label="Search result pages" uk-grid><div><span class="uk-text-meta">Page <?= $e((string) $page) ?> of <?= $e((string) $totalPages) ?></span></div><div class="uk-flex uk-flex-middle uk-grid-small" uk-grid><?php if ($page > 1): ?><div><a class="uk-button uk-button-default uk-link-reset" href="<?= $e($pageUrl($page - 1)) ?>"><i class="fa fa-chevron-left"></i> Previous</a></div><?php endif; ?><?php if ($page < $totalPages): ?><div><a class="uk-button uk-button-default uk-link-reset" href="<?= $e($pageUrl($page + 1)) ?>">Next <i class="fa fa-chevron-right"></i></a></div><?php endif; ?></div></nav><?php endif; ?>
      </div>

      <div class="uk-width-1-1 uk-width-1-3@l"><aside class="uk-card uk-card-default uk-card-small uk-card-body"><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Refine your search</p><h3 class="uk-card-title uk-margin-small-top"><?= $result->total > 0 ? 'Find the right record faster' : 'Try a broader approach' ?></h3><ul class="uk-list uk-list-divider uk-margin-medium-top"><li><strong>People and companies</strong><div class="uk-text-meta uk-margin-small-top">Use a name, email address or company name.</div></li><li><strong>Sales work</strong><div class="uk-text-meta uk-margin-small-top">Use the lead or opportunity title.</div></li><li><strong>Catalog</strong><div class="uk-text-meta uk-margin-small-top">Use a title, SKU or barcode.</div></li><li><strong>Workspaces</strong><div class="uk-text-meta uk-margin-small-top">Use the component name, such as CRM or Inventory.</div></li></ul></aside></div>
    </div>
  <?php elseif ($query !== '' && !$queryReady): ?>
    <section class="uk-card uk-card-default uk-card-small uk-card-body"><div class="uk-placeholder uk-text-center"><i class="fa fa-keyboard-o fa-2x uk-text-muted"></i><h3>Keep typing</h3><p class="uk-text-muted">Enter at least two characters to begin searching.</p></div></section>
  <?php else: ?>
    <div class="uk-grid-medium" uk-grid><div class="uk-width-1-1 uk-width-2-3@l"><section class="uk-card uk-card-default uk-card-small uk-card-body"><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Available coverage</p><h3 class="uk-card-title uk-margin-small-top">Search connected workspaces</h3><p class="uk-text-muted">Only installed components and records available to your role participate in search.</p><div class="uk-grid-small uk-child-width-1-1 uk-child-width-1-2@s uk-margin-medium-top" uk-grid><?php foreach ($availableEntityTypes as $type => $label): ?><?php $presentation = $presentations[$type] ?? ['icon' => 'file-o']; ?><div><div class="uk-flex uk-flex-middle"><span class="kontor-stat__icon uk-margin-small-right"><i class="fa fa-<?= $e($presentation['icon']) ?>"></i></span><strong><?= $e($label) ?></strong></div></div><?php endforeach; ?></div></section></div><div class="uk-width-1-1 uk-width-1-3@l"><aside class="uk-card uk-card-default uk-card-small uk-card-body"><p class="uk-text-meta uk-text-uppercase uk-margin-remove-bottom">Search tips</p><h3 class="uk-card-title uk-margin-small-top">Start with what you know</h3><ol class="uk-list uk-list-divider"><li><div class="uk-flex uk-flex-top"><span class="uk-label uk-margin-small-right">1</span><span><strong>Use a distinctive term</strong><br><span class="uk-text-meta">A surname, company name or SKU works better than a broad word.</span></span></div></li><li><div class="uk-flex uk-flex-top"><span class="uk-label uk-margin-small-right">2</span><span><strong>Search everywhere first</strong><br><span class="uk-text-meta">Narrow the workspace only if there are too many matches.</span></span></div></li><li><div class="uk-flex uk-flex-top"><span class="uk-label uk-margin-small-right">3</span><span><strong>Open the business record</strong><br><span class="uk-text-meta">Results lead directly to the available workspace.</span></span></div></li></ol></aside></div></div>
  <?php endif; ?>
</div>

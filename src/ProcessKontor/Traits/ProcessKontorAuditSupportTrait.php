<?php

namespace ProcessWire;

use Kontor\Collaboration\Domain\Comment;
use Kontor\Collaboration\Domain\Note;
use Kontor\Catalog\Application\PriceListDuplicator;
use Kontor\Catalog\Domain\CatalogItem;
use Kontor\Catalog\Domain\Category;
use Kontor\Catalog\Domain\PriceList;
use Kontor\Catalog\Domain\PriceListEntry;
use Kontor\Catalog\Infrastructure\Persistence\CatalogItemRepository;
use Kontor\Catalog\Infrastructure\Persistence\CategoryRepository;
use Kontor\Catalog\Infrastructure\Persistence\PriceListRepository;
use Kontor\Catalog\Infrastructure\Persistence\PriceRepository;
use Kontor\Catalog\Support\TaxCode;
use Kontor\Catalog\Support\UnitOfMeasure;
use Kontor\Contacts\Application\ContactDuplicateDetector;
use Kontor\Contacts\Application\TagService;
use Kontor\Contacts\Domain\Address;
use Kontor\Contacts\Domain\Company;
use Kontor\Contacts\Domain\Contact;
use Kontor\Contacts\Domain\ContactCompanyMembership;
use Kontor\Contacts\Infrastructure\Persistence\AddressRepository;
use Kontor\Contacts\Infrastructure\Persistence\CompanyRepository;
use Kontor\Contacts\Infrastructure\Persistence\ContactRepository;
use Kontor\Contacts\Infrastructure\Persistence\MembershipRepository;
use Kontor\CRM\Domain\Deal;
use Kontor\CRM\Domain\Lead;
use Kontor\CRM\Domain\Pipeline;
use Kontor\CRM\Domain\Stage;
use Kontor\Core\Application\AuditChangePresenter;
use Kontor\Core\Application\AuditCsvExporter;
use Kontor\Core\Application\AuditLogger;
use Kontor\Core\Application\BackupManager;
use Kontor\Core\Application\BackupOverviewBuilder;
use Kontor\Core\Application\ComponentOverviewBuilder;
use Kontor\Core\Application\EntityActionResolver;
use Kontor\Core\Application\ExportManager;
use Kontor\Core\Application\HealthCheckRunner;
use Kontor\Core\Application\HealthOverviewBuilder;
use Kontor\Core\Application\ImportManager;
use Kontor\Core\Application\NavigationAvailability;
use Kontor\Core\Domain\ImportBatchResult;
use Kontor\Core\Domain\Organization;
use Kontor\Core\Health\CoreHealthCheck;
use Kontor\Core\Infrastructure\Backup\BackupArchiveBuilder;
use Kontor\Core\Infrastructure\ImportExport\FormatResolver;
use Kontor\Core\Infrastructure\Persistence\AuditEventRepository;
use Kontor\Core\Infrastructure\Persistence\OrganizationRepository;
use Kontor\Core\Infrastructure\Registry\ComponentRegistry;
use Kontor\Expenses\Domain\Expense;
use Kontor\Expenses\Domain\ExpenseCategory;
use Kontor\Expenses\Application\LedgerExpensePostingService;
use Kontor\Inventory\Domain\Warehouse;
use Kontor\Invoices\Application\LedgerInvoicePostingService;
use Kontor\Invoices\Domain\Invoice;
use Kontor\Germany\DTO\LocalizedInvoiceInput;
use Kontor\Germany\DTO\LocalizedLineItemInput;
use Kontor\Germany\DTO\LocalizedPartyInput;
use Kontor\Ledger\Domain\Account;
use Kontor\Ledger\DTO\LedgerLineInput;
use Kontor\Payments\Application\LedgerAllocationPostingService;
use Kontor\Payments\Domain\Payment;
use Kontor\Projects\Domain\BillableItem;
use Kontor\Projects\Domain\Project;
use Kontor\Projects\Domain\ProjectMilestone;
use Kontor\Purchasing\Domain\PurchaseOrder;
use Kontor\Purchasing\Domain\Supplier;
use Kontor\Queue\Infrastructure\Persistence\JobRepository;
use Kontor\Sales\Domain\DocumentLine;
use Kontor\Sales\Domain\Quotation;
use Kontor\Search\Application\GlobalSearchService;
use Kontor\SDK\DTO\BackupVerification;
use Kontor\SDK\DTO\AIRequest;
use Kontor\SDK\DTO\ExportContext;
use Kontor\SDK\DTO\ImportContext;
use Kontor\SDK\DTO\ReportQuery;
use Kontor\SDK\DTO\SearchQuery;
use Kontor\SDK\Events\KontorEvent;
use Kontor\SDK\ValueObjects\Money;
use Kontor\SDK\ValueObjects\Uid;
use Kontor\Tasks\Domain\Task;
use Kontor\Workflow\Domain\ApprovalRequest;

/**
 * Administrative feature slice composed by ProcessKontor.
 *
 * @internal
 */
trait ProcessKontorAuditSupportTrait
{

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed>|null $current
     * @param array<string, mixed> $metadata
     */
    private function audit(
        string $component,
        string $entityType,
        string $entityUid,
        string $action,
        ?array $previous = null,
        ?array $current = null,
        array $metadata = [],
    ): void {
        /** @var Kontor $kontor */
        $kontor = $this->wire()->modules->get('Kontor');
        $kontor->container()->get(AuditLogger::class)->record(
            organizationId: $this->organizationInternalId(),
            component: $component,
            entityType: $entityType,
            entityUid: $entityUid,
            action: $action,
            actorType: 'user',
            actorUid: (string) $this->wire()->user->id,
            previous: $previous,
            current: $current,
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function contactAuditSnapshot(Contact $contact): array
    {
        return [
            'displayName' => $contact->displayName,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'status' => $contact->status,
            'jobTitle' => $contact->jobTitle,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function companyAuditSnapshot(Company $company): array
    {
        return [
            'legalName' => $company->legalName,
            'email' => $company->email,
            'phone' => $company->phone,
            'status' => $company->status,
            'registrationNumber' => $company->registrationNumber,
            'fields' => $company->metadata['fields'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogItemAuditSnapshot(CatalogItem $item): array
    {
        return [
            'title' => $item->title,
            'description' => $item->description,
            'itemType' => $item->itemType,
            'sku' => $item->sku,
            'barcode' => $item->barcode,
            'categoryUid' => $item->categoryUid,
            'unitCode' => $item->unitCode,
            'taxCode' => $item->taxCode,
            'salesPrice' => $item->salesPrice?->toString(),
            'purchasePrice' => $item->purchasePrice?->toString(),
            'costPrice' => $item->costPrice?->toString(),
            'trackInventory' => $item->trackInventory,
            'status' => $item->status,
        ];
    }

    private function catalogItemTitle(CatalogItem $item): string
    {
        $language = $this->organization()->defaultLanguage;
        $fallback = reset($item->title);

        return $item->titleIn($language)
            ?? $item->titleIn('en')
            ?? (is_string($fallback) && $fallback !== '' ? $fallback : $this->_('Untitled item'));
    }

    /**
     * @return array<string, string>
     */
    private function catalogFormLanguages(): array
    {
        $languages = [
            'en' => $this->_('English'),
            'fr' => $this->_('French'),
            'de' => $this->_('German'),
            'es' => $this->_('Spanish'),
        ];
        $default = $this->organization()->defaultLanguage;

        if (!isset($languages[$default])) {
            return [$default => strtoupper($default), ...$languages];
        }

        if (array_key_first($languages) === $default) {
            return $languages;
        }

        return [
            $default => $languages[$default],
            ...array_diff_key($languages, [$default => true]),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function catalogUnitOptions(?CatalogItem $item = null): array
    {
        $options = (new UnitOfMeasure())->all();

        if ($item !== null && !isset($options[$item->unitCode])) {
            $options[$item->unitCode] = match ($item->unitCode) {
                'h' => $this->_('Hour'),
                default => sprintf($this->_('Current unit (%s)'), $item->unitCode),
            };
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogCategoryAuditSnapshot(Category $category): array
    {
        return [
            'name' => $category->name,
            'parentUid' => $category->parentUid,
            'sortOrder' => $category->sortOrder,
            'status' => $category->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogPriceListAuditSnapshot(PriceList $priceList): array
    {
        return [
            'name' => $priceList->name,
            'currencyCode' => $priceList->currencyCode,
            'status' => $priceList->status,
            'validFrom' => $priceList->validFrom?->format('Y-m-d'),
            'validTo' => $priceList->validTo?->format('Y-m-d'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogPriceEntryAuditSnapshot(PriceListEntry $entry): array
    {
        return [
            'priceListUid' => $entry->priceListUid,
            'itemUid' => $entry->itemUid,
            'price' => $entry->price->toString(),
            'minQuantity' => $entry->minQuantity,
            'validFrom' => $entry->validFrom?->format('Y-m-d'),
            'validTo' => $entry->validTo?->format('Y-m-d'),
        ];
    }

    private function categoryName(Category $category): string
    {
        $language = $this->organization()->defaultLanguage;
        $fallback = reset($category->name);

        return $category->nameIn($language)
            ?? $category->nameIn('en')
            ?? (is_string($fallback) && $fallback !== '' ? $fallback : $this->_('Untitled category'));
    }

    /**
     * @return array<string, string>
     */
    private function catalogItemNames(): array
    {
        $names = [];

        foreach ($this->catalogItemRepository()->findAll($this->organizationUid(), limit: 500) as $item) {
            $names[$item->uid->toString()] = $this->catalogItemTitle($item);
        }

        return $names;
    }

    /**
     * @return array<string, int>
     */
    private function priceEntryCounts(array $priceLists): array
    {
        $uids = array_map(
            static fn (PriceList $priceList): string => $priceList->uid->toString(),
            $priceLists,
        );

        return $this->priceRepository()->countsForPriceLists($uids);
    }

    private function findPriceEntry(
        string $priceListUid,
        string $itemUid,
        float $minQuantity,
    ): ?PriceListEntry {
        foreach ($this->priceRepository()->forItem($priceListUid, $itemUid) as $entry) {
            if (abs($entry->minQuantity - $minQuantity) < 0.0000001) {
                return $entry;
            }
        }

        throw new Wire404Exception($this->_('Price tier was not found.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function addressAuditSnapshot(Address $address): array
    {
        return [
            'type' => $address->addressType,
            'line1' => $address->line1,
            'city' => $address->city,
            'region' => $address->region,
            'postalCode' => $address->postalCode,
            'countryCode' => $address->countryCode,
            'isPrimary' => $address->isPrimary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationAuditSnapshot(Organization $organization): array
    {
        return [
            'name' => $organization->name,
            'legalName' => $organization->legalName,
            'countryCode' => $organization->countryCode,
            'defaultLanguage' => $organization->defaultLanguage,
            'defaultCurrency' => $organization->defaultCurrency,
            'timezone' => $organization->timezone,
            'dateFormat' => $organization->settings['dateFormat'] ?? 'Y-m-d',
            'companyFields' => $organization->settings['companyFields'] ?? [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function backupSummaries(): array
    {
        $summaries = [];

        foreach (array_reverse($this->backupManager()->list()) as $path) {
            $metadataPath = $path . DIRECTORY_SEPARATOR . 'metadata.json';

            try {
                $metadata = is_file($metadataPath)
                    ? json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR)
                    : [];
                $component = (string) ($metadata['component'] ?? '');

                if (!in_array($component, ['core', 'contacts', 'catalog'], true)) {
                    continue;
                }

                $kind = (string) ($metadata['kind'] ?? 'snapshot');
                $verified = $this->backupManager()->verify(
                    $path,
                    $component,
                    $this->organizationUid(),
                    $kind
                );
                $summaries[] = [
                    'id' => basename($path),
                    'component' => $component,
                    'kind' => $kind,
                    'createdAt' => (string) ($metadata['exportedAt'] ?? date(DATE_ATOM, filemtime($path) ?: time())),
                    'itemCount' => array_sum(array_map('intval', $metadata['rowCounts'] ?? [])),
                    'sizeBytes' => $this->directorySize($path),
                    'verified' => $verified,
                ];
            } catch (\Throwable) {
                $summaries[] = [
                    'id' => basename($path),
                    'component' => 'unknown',
                    'kind' => 'unknown',
                    'createdAt' => date(DATE_ATOM, filemtime($path) ?: time()),
                    'itemCount' => 0,
                    'sizeBytes' => $this->directorySize($path),
                    'verified' => false,
                ];
            }
        }

        return $summaries;
    }

    private function directorySize(string $path): int
    {
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }

        return $bytes;
    }

    /**
     * @param array<string, string> $entities
     * @return array<string, string>
     */
    private function demoEntityLinks(array $entities): array
    {
        $routes = [
            'contact' => 'contact/',
            'company' => 'company/',
            'lead' => 'crm-lead/',
            'deal' => 'crm-deal/',
            'catalog_item' => 'catalog-item/',
            'quotation' => 'sales-quotation/',
            'order' => 'sales-order/',
            'task' => 'task/',
            'project' => 'project/',
            'invoice' => 'invoice/',
            'payment' => 'payment/',
            'mail_message' => 'mail/',
            'file' => 'files/',
        ];
        $links = [];
        foreach ($entities as $type => $uid) {
            if (isset($routes[$type])) {
                $links[$type] = $this->wire()->config->urls->admin
                    . 'kontor/' . $routes[$type]
                    . '?id=' . rawurlencode($uid);
            }
        }

        return $links;
    }

    private function renderTemplate(string $name, array $variables): string
    {
        $variables['adminUrl'] = $this->wire()->config->urls->admin . 'kontor/';
        $variables['csrfName'] = $this->wire()->session->CSRF->getTokenName();
        $variables['csrfValue'] = $this->wire()->session->CSRF->getTokenValue();
        $variables['e'] = static fn (mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        if (($variables['form'] ?? null) instanceof InputfieldForm) {
            $this->addFormGuidance($variables['form']);
        }

        extract($variables, EXTR_SKIP);
        ob_start();
        include dirname(__DIR__, 3) . '/templates/admin/' . $name . '.php';

        return (string) ob_get_clean();
    }

    private function addFormGuidance(InputfieldForm $form): void
    {
        foreach ($form->getAll() as $field) {
            if (!$field instanceof Inputfield || $field instanceof InputfieldSubmit) {
                continue;
            }

            [$description, $notes] = $this->fieldGuidance(
                (string) $field->name,
                trim(strip_tags((string) $field->label)),
                $field instanceof InputfieldTextarea,
                $field instanceof InputfieldSelect,
                (bool) $field->required,
            );

            if (trim((string) $field->description) === '') {
                $field->description = $description;
            }
            if (trim((string) $field->notes) === '') {
                $field->notes = $notes;
            }
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function fieldGuidance(
        string $name,
        string $label,
        bool $textarea,
        bool $select,
        bool $required,
    ): array {
        $normalized = strtolower($name);
        $label = $label !== '' ? $label : $this->_('this value');
        $descriptions = [
            'display_name' => $this->_('The name teammates will see in contact lists, search results and linked records.'),
            'first_name' => $this->_('The person’s given name, used for greetings and document personalization.'),
            'last_name' => $this->_('The person’s family name, used for sorting and formal communication.'),
            'legal_name' => $this->_('The registered name used on contracts, invoices and official documents.'),
            'trading_name' => $this->_('The public-facing company name used in everyday communication.'),
            'registration_number' => $this->_('The identifier assigned by the company register or equivalent authority.'),
            'email' => $this->_('The primary address used for communication and duplicate detection.'),
            'phone' => $this->_('The main telephone number for this record.'),
            'mobile' => $this->_('A direct mobile number for time-sensitive communication.'),
            'job_title' => $this->_('The person’s role or position in their organization.'),
            'website' => $this->_('The organization’s primary public website.'),
            'vat_number' => $this->_('The tax registration number shown on applicable business documents.'),
            'notes' => $this->_('Internal context that helps teammates understand and work with this record.'),
            'item_type' => $this->_('Controls whether the catalog entry behaves as a physical product or a service.'),
            'status' => $this->_('Controls whether this record is available in active workflows and selections.'),
            'sku' => $this->_('Your internal stock-keeping code for search, imports and integrations.'),
            'barcode' => $this->_('The scannable product identifier supplied by the manufacturer or your organization.'),
            'unit_code' => $this->_('The unit used when quoting, ordering, invoicing and tracking quantities.'),
            'tax_code' => $this->_('The tax treatment applied when this item is used in commercial documents.'),
            'category_uid' => $this->_('Places the item in the catalog hierarchy for browsing, reporting and rules.'),
            'parent_uid' => $this->_('Places this category below another category in the catalog hierarchy.'),
            'sort_order' => $this->_('Controls the category’s position relative to sibling categories.'),
            'track_inventory' => $this->_('Enables stock balances, reservations and movements for this item.'),
            'name' => $this->_('The clear internal name teammates will use to identify this record.'),
            'currency_code' => $this->_('The currency used by all monetary values in this record.'),
            'default_currency' => $this->_('The currency preselected for new commercial and financial records.'),
            'default_language' => $this->_('The language used as the primary content and document fallback.'),
            'country_code' => $this->_('The organization’s home country for localization and compliance defaults.'),
            'timezone' => $this->_('The timezone used to interpret dates, deadlines and scheduled activity.'),
            'date_format' => $this->_('Controls how dates are displayed throughout the Kontor workspace.'),
            'item_uid' => $this->_('The catalog item whose price and quantity tier this entry defines.'),
            'min_quantity' => $this->_('The quantity from which this price tier becomes effective.'),
            'entry_price' => $this->_('The unit price applied when the minimum quantity is reached.'),
            'entry_currency' => $this->_('The currency inherited by this price tier.'),
            'valid_from' => $this->_('The first calendar date on which this record may be used.'),
            'valid_to' => $this->_('The final calendar date on which this record may be used.'),
        ];

        if (isset($descriptions[$normalized])) {
            $description = $descriptions[$normalized];
        } elseif (preg_match('/^title_[a-z-]+$/', $normalized) === 1) {
            $description = $this->_('The localized title shown in catalog views and customer-facing documents.');
        } elseif (preg_match('/^description_[a-z-]+$/', $normalized) === 1) {
            $description = $this->_('The localized detail text available to sales documents, portals and integrations.');
        } elseif (str_ends_with($normalized, '_currency')) {
            $description = $this->_('The ISO currency used to interpret the adjacent monetary amount.');
        } elseif (str_ends_with($normalized, '_price') || str_ends_with($normalized, '_amount')) {
            $description = sprintf($this->_('The monetary value recorded as %s.'), strtolower($label));
        } elseif ($select) {
            $description = sprintf($this->_('Choose the option that controls %s for this record.'), strtolower($label));
        } elseif ($textarea) {
            $description = sprintf($this->_('Add the context teammates need when working with %s.'), strtolower($label));
        } else {
            $description = sprintf($this->_('The value used for %s in this record and its connected workflows.'), strtolower($label));
        }

        if (
            str_contains($normalized, 'date')
            || in_array($normalized, ['valid_from', 'valid_to'], true)
        ) {
            $notes = $this->_('Use YYYY-MM-DD. Leave blank when no date limit should apply.');
        } elseif ($normalized === 'status') {
            $notes = $this->_('Preserve business history by changing status instead of deleting the record.');
        } elseif (str_contains($normalized, 'currency')) {
            $notes = $this->_('Use the three-letter ISO 4217 currency code, for example EUR or USD.');
        } elseif (
            str_ends_with($normalized, '_price')
            || str_ends_with($normalized, '_amount')
            || $normalized === 'entry_price'
        ) {
            $notes = $this->_('Enter a decimal amount without a currency symbol, for example 129.90.');
        } elseif (str_contains($normalized, 'language')) {
            $notes = $this->_('Other translations fall back to this language when content is missing.');
        } elseif ($normalized === 'country_code') {
            $notes = $this->_('Use the two-letter ISO 3166-1 code, for example DE or US.');
        } elseif ($normalized === 'sort_order') {
            $notes = $this->_('Lower numbers appear first. Items with the same value keep their natural order.');
        } elseif ($normalized === 'notes' || $textarea) {
            $notes = $this->_('Keep this concise and avoid passwords, secrets or unnecessary personal data.');
        } else {
            $notes = $required
                ? $this->_('Required. Review this value before saving.')
                : $this->_('Optional. Leave blank when the information is not known or does not apply.');
        }

        return [$description, $notes];
    }

    private function setPageTitle(string $title): void
    {
        $title = preg_replace('/^Kontor\s+·\s+/u', '', trim($title)) ?? trim($title);
        $this->headline($title);
        $this->browserTitle($title);
        $this->configureBreadcrumbs();
    }

    private function configureBreadcrumbs(): void
    {
        $segment = trim((string) $this->wire()->input->urlSegment1, '/');
        if ($segment === '') {
            return;
        }

        $adminUrl = $this->wire()->config->urls->admin . 'kontor/';
        $trail = match ($segment) {
            'contact' => [['contacts/', 'Contacts']],
            'company' => [['companies/', 'Companies']],
            'crm-deals' => [['crm/', 'CRM']],
            'crm-lead', 'crm-pipeline', 'crm-intake' => [['crm/', 'CRM']],
            'crm-deal' => [['crm/', 'CRM'], ['crm-deals/', 'Deals']],
            'sales-quotation', 'sales-order' => [['sales/', 'Sales']],
            'invoice' => [['invoices/', 'Invoices']],
            'payment' => [['payments/', 'Payments']],
            'task' => [['tasks/', 'Tasks']],
            'inventory-warehouse', 'inventory-movement' => [['inventory/', 'Inventory']],
            'purchasing-supplier', 'purchase-order', 'purchasing-receipt' => [
                ['purchasing/', 'Purchasing'],
            ],
            'expense-category', 'expense' => [['expenses/', 'Expenses']],
            'project' => [['projects/', 'Projects']],
            'workflow' => [['workflows/', 'Workflows']],
            'workflow-instance' => [['workflows/', 'Workflows']],
            'automation' => [['automations/', 'Automations']],
            'custom-entity' => [['custom-entities/', 'Custom entities']],
            'custom-entity-record' => [['custom-entities/', 'Custom entities']],
            'catalog-references', 'catalog-categories', 'catalog-price-lists' => [
                ['catalog/', 'Catalog'],
            ],
            'catalog-item' => [['catalog/', 'Catalog']],
            'catalog-category' => [
                ['catalog/', 'Catalog'],
                ['catalog-categories/', 'Categories'],
            ],
            'catalog-price-list' => [
                ['catalog/', 'Catalog'],
                ['catalog-price-lists/', 'Price lists'],
            ],
            'catalog-price-entry' => [
                ['catalog/', 'Catalog'],
                ['catalog-price-lists/', 'Price lists'],
            ],
            'documents' => (string) $this->wire()->input->get('id') !== ''
                ? [['documents/', 'Documents']]
                : [],
            'ledger' => (string) $this->wire()->input->get('id') !== ''
                ? [['ledger/', 'Ledger']]
                : [],
            'mail' => (string) $this->wire()->input->get('id') !== ''
                ? [['mail/', 'Mail']]
                : [],
            'import' => $this->importBreadcrumbTrail(),
            default => [],
        };

        $this->breadcrumb($adminUrl, $this->_('Kontor'));
        foreach ($trail as [$path, $label]) {
            $this->breadcrumb($adminUrl . $path, $this->_($label));
        }
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function importBreadcrumbTrail(): array
    {
        return match ((string) $this->wire()->input->get('entity')) {
            'contact' => [['contacts/', 'Contacts']],
            'company' => [['companies/', 'Companies']],
            'catalog_item' => [['catalog/', 'Catalog']],
            default => [],
        };
    }

    private function requirePermission(string $permission): void
    {
        $user = $this->wire()->user;

        if (!$user->isSuperuser() && !$user->hasPermission($permission)) {
            throw new WirePermissionException(
                sprintf($this->_('You do not have the required permission: %s'), $permission)
            );
        }
    }

    private function requirePost(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            throw new WireException($this->_('This action requires a POST request.'));
        }

        $this->wire()->session->CSRF->validate();
    }

    private function requireSameOrganization(string $organizationUid): void
    {
        if (!hash_equals($this->organizationUid(), $organizationUid)) {
            throw new WirePermissionException($this->_('This record belongs to another organization.'));
        }
    }

    /**
     * @param string[] $allowed
     */
    private function requireAction(?string $action, array $allowed): void
    {
        if ($action === null || !in_array($action, $allowed, true)) {
            throw new WireException($this->_('Invalid action.'));
        }
    }

    /**
     * @return array{
     *   query: string,
     *   component: string|null,
     *   entityType: string|null,
     *   action: string|null,
     *   options: array{components: string[], entityTypes: string[], actions: string[]}
     * }
     */
    private function activityFilterSelection(int $organizationId): array
    {
        $options = $this->auditEventRepository()->filterOptions($organizationId);
        $query = $this->wire()->sanitizer->text((string) $this->wire()->input->get('q'));
        $component = $this->wire()->sanitizer->text((string) $this->wire()->input->get('component'));
        $entityType = $this->wire()->sanitizer->text((string) $this->wire()->input->get('entity_type'));
        $action = $this->wire()->sanitizer->text((string) $this->wire()->input->get('action'));

        return [
            'query' => $query,
            'component' => $component !== '' ? $component : null,
            'entityType' => $entityType !== '' ? $entityType : null,
            'action' => $action !== '' ? $action : null,
            'options' => $options,
        ];
    }
}

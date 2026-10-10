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

require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorNavigationTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorCustomerTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorCommerceTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorWorkTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorOperationsTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorWorkflowTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorCommunicationsTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorContentTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorPlatformTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorCatalogTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorAdministrationTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorFormsTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorAuditSupportTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorModuleAccessTrait.php';
require_once __DIR__ . '/src/ProcessKontor/Traits/ProcessKontorWorkspaceSupportTrait.php';

/**
 * The single Kontor admin application. Business components provide the
 * domain services while this Process module owns navigation and UI.
 */
class ProcessKontor extends Process
{
    private const QUICK_NAVIGATION_META = 'kontor.quick_navigation';
    private const QUICK_NAVIGATION_LIMIT = 8;
    private const DASHBOARD_INTRO_META = 'kontor.dashboard_intro';
    private const SETTINGS_IMPORT_SESSION = 'settingsMigrationPreview';
    private const DEFAULT_DASHBOARD_HEADLINE = 'Your business, in one place.';
    private const DEFAULT_DASHBOARD_MESSAGE = 'Kontor connects customer data, companies and operational components inside ProcessWire.';
    private const DEFAULT_QUICK_NAVIGATION = [
        'contacts',
        'companies',
        'crm',
        'sales',
        'tasks',
    ];

    public static function getModuleInfo(): array
    {
        return [
            'title' => 'Kontor',
            'summary' => 'Kontor ERP, CRM and business operations admin.',
            'version' => '111',
            'author' => 'Maxim Semenov',
            'icon' => 'cubes',
            'permission' => 'kontor-access',
            'requires' => ['Kontor'],
            'page' => [
                'name' => 'kontor',
                'title' => 'Kontor',
            ],
            'useNavJSON' => false,
            'nav' => [
                ['url' => '', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                [
                    'url' => 'contacts/',
                    'label' => 'Contacts',
                    'icon' => 'address-book',
                    'permission' => 'kontor-contacts-contact-view',
                ],
                [
                    'url' => 'companies/',
                    'label' => 'Companies',
                    'icon' => 'building',
                    'permission' => 'kontor-contacts-company-view',
                ],
                [
                    'url' => 'crm/',
                    'label' => 'CRM',
                    'icon' => 'handshake-o',
                    'permission' => 'kontor-crm-lead-view',
                ],
                [
                    'url' => 'sales/',
                    'label' => 'Sales',
                    'icon' => 'file-text-o',
                    'permission' => 'kontor-sales-quotation-view',
                ],
                [
                    'url' => 'tasks/',
                    'label' => 'Tasks',
                    'icon' => 'check-square-o',
                    'permission' => 'kontor-tasks-task-view',
                ],
                [
                    'url' => 'components/',
                    'label' => 'Components',
                    'icon' => 'cubes',
                    'permission' => 'kontor-components-view',
                ],
                ['url' => 'sections/', 'label' => 'Quick Access', 'icon' => 'th-large'],
            ],
            'kontorNavigation' => [
                ['url' => '', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                [
                    'url' => 'demo/',
                    'label' => 'Demo',
                    'icon' => 'play-circle',
                    'module' => 'KontorDemo',
                    'permission' => 'kontor-demo-view',
                ],
                ['url' => 'search/', 'label' => 'Search', 'icon' => 'search', 'module' => 'KontorSearch'],
                [
                    'url' => 'activity/',
                    'label' => 'Activity',
                    'icon' => 'history',
                    'permission' => 'kontor-audit-view',
                ],
                [
                    'url' => 'backups/',
                    'label' => 'Backups',
                    'icon' => 'database',
                    'permission' => 'kontor-backups-view',
                ],
                [
                    'url' => 'health/',
                    'label' => 'Health',
                    'icon' => 'heartbeat',
                    'permission' => 'kontor-health-view',
                ],
                [
                    'url' => 'queue/',
                    'label' => 'Queue',
                    'icon' => 'tasks',
                    'module' => 'KontorQueue',
                    'permission' => 'kontor-queue-view',
                ],
                [
                    'url' => 'organization/',
                    'label' => 'Organization',
                    'icon' => 'briefcase',
                    'permission' => 'kontor-admin',
                ],
                [
                    'url' => 'settings-migration/',
                    'label' => 'Settings migration',
                    'icon' => 'exchange',
                    'module' => 'KontorSettings',
                    'permission' => 'kontor-settings-export',
                ],
                [
                    'url' => 'catalog/',
                    'label' => 'Catalog',
                    'icon' => 'cubes',
                    'module' => 'KontorCatalog',
                    'permission' => 'kontor-catalog-item-view',
                ],
                [
                    'url' => 'inventory/',
                    'label' => 'Inventory',
                    'icon' => 'cube',
                    'module' => 'KontorInventory',
                    'permission' => 'kontor-inventory-stock-view',
                ],
                [
                    'url' => 'purchasing/',
                    'label' => 'Purchasing',
                    'icon' => 'truck',
                    'module' => 'KontorPurchasing',
                    'permission' => 'kontor-purchasing-po-view',
                ],
                [
                    'url' => 'expenses/',
                    'label' => 'Expenses',
                    'icon' => 'credit-card',
                    'module' => 'KontorExpenses',
                    'permission' => 'kontor-expenses-expense-view',
                ],
                [
                    'url' => 'projects/',
                    'label' => 'Projects',
                    'icon' => 'tasks',
                    'module' => 'KontorProjects',
                    'permission' => 'kontor-projects-project-view',
                ],
                [
                    'url' => 'workflows/',
                    'label' => 'Workflows',
                    'icon' => 'sitemap',
                    'module' => 'KontorWorkflow',
                    'permission' => 'kontor-workflow-definition-view',
                ],
                [
                    'url' => 'automations/',
                    'label' => 'Automations',
                    'icon' => 'bolt',
                    'module' => 'KontorAutomation',
                    'permission' => 'kontor-automation-rule-view',
                ],
                [
                    'url' => 'custom-entities/',
                    'label' => 'Custom entities',
                    'icon' => 'cube',
                    'module' => 'KontorEntities',
                    'permission' => 'kontor-entities-record-view',
                ],
                [
                    'url' => 'api/',
                    'label' => 'API',
                    'icon' => 'plug',
                    'module' => 'KontorAPI',
                    'permission' => 'kontor-api-token-manage',
                ],
                [
                    'url' => 'graphql/',
                    'label' => 'GraphQL',
                    'icon' => 'share-alt',
                    'module' => 'KontorGraphQL',
                    'permission' => 'kontor-api-token-manage',
                ],
                [
                    'url' => 'marketplace/',
                    'label' => 'Marketplace',
                    'icon' => 'shopping-cart',
                    'module' => 'KontorMarketplace',
                    'permission' => 'kontor-marketplace-advisory-view',
                ],
                [
                    'url' => 'mail/',
                    'label' => 'Mail',
                    'icon' => 'envelope',
                    'module' => 'KontorMail',
                    'permission' => 'kontor-mail-message-view',
                ],
                [
                    'url' => 'portal/',
                    'label' => 'Portal',
                    'icon' => 'user-circle',
                    'module' => 'KontorPortal',
                    'permission' => 'kontor-portal-account-manage',
                ],
                [
                    'url' => 'files/',
                    'label' => 'Files',
                    'icon' => 'folder-open',
                    'module' => 'KontorFiles',
                    'permission' => 'kontor-files-file-view',
                ],
                [
                    'url' => 'cache/',
                    'label' => 'Cache',
                    'icon' => 'bolt',
                    'module' => 'KontorCache',
                    'permission' => 'kontor-cache-view',
                ],
                [
                    'url' => 'documents/',
                    'label' => 'Documents',
                    'icon' => 'file-pdf-o',
                    'module' => 'KontorDocuments',
                    'permission' => 'kontor-documents-template-view',
                ],
                [
                    'url' => 'ai/',
                    'label' => 'AI',
                    'icon' => 'magic',
                    'module' => 'KontorAI',
                    'permission' => 'kontor-ai-action-approve',
                ],
                [
                    'url' => 'ledger/',
                    'label' => 'Ledger',
                    'icon' => 'balance-scale',
                    'module' => 'KontorLedger',
                    'permission' => 'kontor-ledger-entry-view',
                ],
                [
                    'url' => 'germany/',
                    'label' => 'Germany',
                    'icon' => 'flag',
                    'module' => 'KontorGermany',
                    'permission' => 'kontor-germany-view',
                ],
                [
                    'url' => 'contacts/',
                    'label' => 'Contacts',
                    'icon' => 'address-book',
                    'module' => 'KontorContacts',
                    'permission' => 'kontor-contacts-contact-view',
                ],
                [
                    'url' => 'companies/',
                    'label' => 'Companies',
                    'icon' => 'building',
                    'module' => 'KontorContacts',
                    'permission' => 'kontor-contacts-company-view',
                ],
                [
                    'url' => 'crm/',
                    'label' => 'CRM',
                    'icon' => 'handshake-o',
                    'module' => 'KontorCRM',
                    'permission' => 'kontor-crm-lead-view',
                ],
                [
                    'url' => 'sales/',
                    'label' => 'Sales',
                    'icon' => 'file-text-o',
                    'module' => 'KontorSales',
                    'permission' => 'kontor-sales-quotation-view',
                ],
                [
                    'url' => 'invoices/',
                    'label' => 'Invoices',
                    'icon' => 'file-text',
                    'module' => 'KontorInvoices',
                    'permission' => 'kontor-invoices-invoice-view',
                ],
                [
                    'url' => 'payments/',
                    'label' => 'Payments',
                    'icon' => 'money',
                    'module' => 'KontorPayments',
                    'permission' => 'kontor-payments-payment-view',
                ],
                [
                    'url' => 'tasks/',
                    'label' => 'Tasks',
                    'icon' => 'check-square-o',
                    'module' => 'KontorTasks',
                    'permission' => 'kontor-tasks-task-view',
                ],
                [
                    'url' => 'collaboration/',
                    'label' => 'Collaboration',
                    'icon' => 'comments-o',
                    'module' => 'KontorCollaboration',
                    'permission' => 'kontor-collaboration-comment-view',
                ],
                [
                    'url' => 'reports/',
                    'label' => 'Reports',
                    'icon' => 'bar-chart',
                    'module' => 'KontorReports',
                    'permission' => 'kontor-reports-report-view',
                ],
                [
                    'url' => 'components/',
                    'label' => 'Components',
                    'icon' => 'cubes',
                    'permission' => 'kontor-components-view',
                ],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->addHookAfter(
            'AdminThemeFramework::getPrimaryNavArray',
            $this,
            'customizePrimaryNavigation',
        );
        $this->refreshNavigationCache();
        $moduleUrl = $this->wire()->config->urls->get('ProcessKontor');
        $version = (string) max(
            @filemtime(__DIR__ . '/assets/kontor.admin.css') ?: 0,
            @filemtime(__DIR__ . '/assets/kontor.admin.js') ?: 0,
            (int) self::getModuleInfo()['version'],
        );
        $this->wire()->config->styles->add($moduleUrl . 'assets/kontor.admin.css?v=' . $version);
        $this->wire()->config->scripts->add($moduleUrl . 'assets/kontor.admin.js?v=' . $version);
    }

    use ProcessKontorNavigationTrait;
    use ProcessKontorCustomerTrait;
    use ProcessKontorCommerceTrait;
    use ProcessKontorWorkTrait;
    use ProcessKontorOperationsTrait;
    use ProcessKontorWorkflowTrait;
    use ProcessKontorCommunicationsTrait;
    use ProcessKontorContentTrait;
    use ProcessKontorPlatformTrait;
    use ProcessKontorCatalogTrait;
    use ProcessKontorAdministrationTrait;
    use ProcessKontorFormsTrait;
    use ProcessKontorAuditSupportTrait;
    use ProcessKontorModuleAccessTrait;
    use ProcessKontorWorkspaceSupportTrait;

}

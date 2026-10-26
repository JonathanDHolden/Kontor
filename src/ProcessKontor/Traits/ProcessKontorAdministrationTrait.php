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
trait ProcessKontorAdministrationTrait
{

    public function ___executeActivity(): string
    {
        $this->requirePermission('kontor-audit-view');
        $this->setPageTitle($this->_('Kontor · Activity'));
        $organizationId = $this->organizationInternalId();
        $filters = $this->activityFilterSelection($organizationId);
        $query = $filters['query'];
        $component = $filters['component'];
        $entityType = $filters['entityType'];
        $action = $filters['action'];
        $pageSize = 50;
        $totalEvents = $this->auditEventRepository()->countMatching(
            $organizationId,
            $query,
            $component,
            $entityType,
            $action,
        );
        $totalPages = max(1, (int) ceil($totalEvents / $pageSize));
        $page = min(
            $totalPages,
            max(1, (int) $this->wire()->input->get('page'))
        );
        $events = $this->auditEventRepository()->findRecent(
            $organizationId,
            $query,
            $pageSize,
            $component,
            $entityType,
            $action,
            ($page - 1) * $pageSize,
        );
        $actorLabels = [];
        foreach ($events as $event) {
            if ($event->actorType !== 'user' || $event->actorUid === null || isset($actorLabels[$event->actorUid])) {
                continue;
            }
            $actor = ctype_digit($event->actorUid)
                ? $this->wire()->users->get((int) $event->actorUid)
                : null;
            if ($actor !== null && $actor->id) {
                $label = trim((string) ($actor->get('title') ?: $actor->get('name')));
                $actorLabels[$event->actorUid] = $actor->isSuperuser() && strtolower($label) === 'admin'
                    ? $this->_('Administrator')
                    : ($label !== '' ? $label : $this->_('User'));
            }
        }

        return $this->renderTemplate('activity', [
            'events' => $events,
            'query' => $query,
            'filterOptions' => $filters['options'],
            'selectedComponent' => $component,
            'selectedEntityType' => $entityType,
            'selectedAction' => $action,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalEvents' => $totalEvents,
            'changePresenter' => new AuditChangePresenter(),
            'actorLabels' => $actorLabels,
            'organizationName' => $this->organization()->name,
        ]);
    }

    public function ___executeActivityExport(): void
    {
        $this->requirePermission('kontor-audit-view');
        $organizationId = $this->organizationInternalId();
        $filters = $this->activityFilterSelection($organizationId);
        $temporary = tempnam($this->wire()->config->paths->cache, 'kontor_activity_');

        if ($temporary === false) {
            throw new WireException($this->_('Could not create an Activity export file.'));
        }

        $path = $temporary . '.csv';
        rename($temporary, $path);
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });
        $count = (new AuditCsvExporter())->export(
            $path,
            $this->auditEventRepository()->iterateMatching(
                $organizationId,
                $filters['query'],
                $filters['component'],
                $filters['entityType'],
                $filters['action'],
            ),
        );
        $activeFilters = array_filter([
            'query' => $filters['query'],
            'component' => $filters['component'],
            'entityType' => $filters['entityType'],
            'action' => $filters['action'],
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        $this->audit(
            'core',
            'audit',
            'bulk',
            'exported',
            metadata: ['format' => 'csv', 'recordCount' => $count, 'filters' => $activeFilters],
        );
        $filename = 'kontor-activity-' . (new \DateTimeImmutable())->format('Y-m-d') . '.csv';
        wireSendFile($path, [
            'forceDownload' => true,
            'downloadFilename' => $filename,
            'exit' => true,
        ]);
    }

    public function ___executeBackups(): string
    {
        $this->requirePermission('kontor-backups-view');
        $this->setPageTitle($this->_('Kontor · Backups'));
        $query = $this->wire()->sanitizer->text((string) $this->wire()->input->get('q'));
        $component = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->get('component'),
            ['core', 'contacts', 'catalog', 'unknown']
        ) ?? '';
        $status = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->get('status'),
            ['verified', 'failed']
        ) ?? '';
        $overview = (new BackupOverviewBuilder())->build(
            $this->backupSummaries(),
            $query,
            $component,
            $status,
            max(1, (int) $this->wire()->input->get('page')),
        );

        return $this->renderTemplate('backups', [
            'backups' => $overview['backups'],
            'query' => $query,
            'selectedComponent' => $component,
            'selectedStatus' => $status,
            'page' => $overview['page'],
            'totalPages' => $overview['totalPages'],
            'totalBackups' => $overview['total'],
            'canDownloadBackups' => $this->wire()->user->isSuperuser()
                || $this->wire()->user->hasPermission('kontor-backups-download'),
        ]);
    }

    public function ___executeHealth(): string
    {
        $this->requirePermission('kontor-health-view');
        $this->setPageTitle($this->_('Kontor · Health'));
        $checks = [$this->coreHealthCheck()];
        $query = $this->wire()->sanitizer->text((string) $this->wire()->input->get('q'));
        $status = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->get('status'),
            ['ok', 'warning', 'critical']
        ) ?? '';

        foreach ([
            'KontorQueue', 'KontorFiles', 'KontorSearch', 'KontorAPI',
            'KontorContacts', 'KontorCatalog', 'KontorCRM', 'KontorSales',
            'KontorInvoices', 'KontorPayments', 'KontorTasks', 'KontorCollaboration',
            'KontorDashboard', 'KontorReports', 'KontorInventory', 'KontorPurchasing',
            'KontorExpenses', 'KontorProjects', 'KontorWorkflow', 'KontorAutomation',
            'KontorEntities', 'KontorGraphQL', 'KontorMarketplace', 'KontorMail',
            'KontorPortal', 'KontorCache', 'KontorDocuments', 'KontorAI',
            'KontorLedger', 'KontorSettings', 'KontorMCP', 'KontorCRMIntake', 'KontorDemo',
        ] as $moduleName) {
            if (!$this->wire()->modules->isInstalled($moduleName)) {
                continue;
            }

            $module = $this->wire()->modules->get($moduleName);

            if (is_object($module) && method_exists($module, 'healthCheck')) {
                $checks[] = $module->healthCheck();
            }
        }

        $overview = (new HealthOverviewBuilder())->build(
            $this->healthCheckRunner()->run($checks),
            $query,
            $status,
        );

        return $this->renderTemplate('health', [
            'checks' => $overview['checks'],
            'counts' => $overview['counts'],
            'overall' => $overview['overall'],
            'query' => $query,
            'selectedStatus' => $status,
            'checkedAt' => new \DateTimeImmutable(),
        ]);
    }

    public function ___executeOrganization(): string
    {
        $this->requirePermission('kontor-admin');
        $this->setPageTitle($this->_('Kontor · Organization'));
        $organization = $this->organization();
        $form = $this->buildOrganizationForm($organization);

        if ($this->wire()->input->post('submit_save')) {
            $this->requirePost();
            $form->processInput($this->wire()->input->post);
            $countryCode = strtoupper($this->requiredFormValue($form, 'country_code'));
            $language = $this->requiredFormValue($form, 'default_language');
            $currency = strtoupper($this->requiredFormValue($form, 'default_currency'));
            $timezone = $this->requiredFormValue($form, 'timezone');

            if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
                $form->getChildByName('country_code')?->error($this->_('Use a two-letter ISO country code.'));
            }

            if (preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $language) !== 1) {
                $form->getChildByName('default_language')?->error($this->_('Choose a supported language.'));
            }

            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $form->getChildByName('default_currency')?->error($this->_('Use a three-letter ISO currency code.'));
            }

            if (!in_array($timezone, $this->allowedTimezones(), true)) {
                $form->getChildByName('timezone')?->error($this->_('Choose a valid IANA timezone.'));
            }

            if (!$form->getErrors()) {
                $previous = $this->organizationAuditSnapshot($organization);
                $organization->name = $this->requiredFormValue($form, 'name');
                $organization->legalName = $this->formValue($form, 'legal_name');
                $organization->countryCode = $countryCode;
                $organization->defaultLanguage = $language;
                $organization->defaultCurrency = $currency;
                $organization->timezone = $timezone;
                $organization->settings['dateFormat'] = $this->requiredFormValue($form, 'date_format');
                $organization->settings['companyFields'] = $this->parseCompanyFields((string) $this->formValue($form, 'company_fields'));
                $current = $this->organizationAuditSnapshot($organization);

                if ($previous !== $current) {
                    $this->organizationRepository()->save($organization);
                    $this->audit(
                        'core',
                        'organization',
                        $organization->uid->toString(),
                        'updated',
                        previous: $previous,
                        current: $current,
                    );
                    $this->message($this->_('Organization settings saved.'));
                } else {
                    $this->message($this->_('Organization settings are already up to date.'));
                }

                $this->wire()->session->redirect('./');
            }
        }

        return $this->renderTemplate('organization', [
            'organization' => $organization,
            'form' => $form,
        ]);
    }

    public function ___executeSettingsMigration(): string
    {
        $this->requireSettings();
        $canExport = $this->can('kontor-settings-export');
        $canImport = $this->can('kontor-settings-import');

        if (!$canExport && !$canImport) {
            throw new WirePermissionException($this->_('You do not have access to settings migration.'));
        }

        $this->setPageTitle($this->_('Kontor · Settings migration'));
        $stored = $this->wire()->session->getFor($this, self::SETTINGS_IMPORT_SESSION);
        $providers = [];

        foreach ($this->settingsModule()->providerRegistry()->all() as $key => $provider) {
            $providers[] = ['key' => $key, 'label' => $provider->label()];
        }

        return $this->renderTemplate('settings-migration', [
            'providers' => $providers,
            'preview' => is_array($stored) ? ($stored['report'] ?? null) : null,
            'canExport' => $canExport,
            'canImport' => $canImport,
        ]);
    }

    public function ___executeSettingsExport(): void
    {
        $this->requireSettings();
        $this->requirePermission('kontor-settings-export');
        $components = array_map(
            static fn (array $component): array => [
                'name' => (string) ($component['name'] ?? ''),
                'version' => (string) ($component['version'] ?? ''),
                'status' => (string) ($component['status'] ?? ''),
            ],
            $this->componentRegistry()->all(),
        );
        $profile = $this->settingsModule()->migrationService()->exportProfile([
            'host' => (string) $this->wire()->config->httpHost,
            'components' => $components,
        ]);
        $json = json_encode(
            $profile,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
        $fingerprint = $this->settingsModule()->migrationService()->fingerprint($profile);
        $this->audit(
            'settings',
            'settings_profile',
            'workspace-settings',
            'exported',
            metadata: [
                'fingerprint' => $fingerprint,
                'providerCount' => count($profile['providers']),
            ],
        );
        $name = preg_replace('/[^a-z0-9]+/i', '-', $this->organization()->name) ?: 'workspace';
        $filename = sprintf('kontor-settings-%s-%s.json', strtolower(trim($name, '-')), date('Y-m-d'));

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: private, no-store');
        echo $json;
        exit;
    }

    public function ___executeSettingsPreview(): void
    {
        $this->requirePost();
        $this->requireSettings();
        $this->requirePermission('kontor-settings-import');
        $json = trim((string) $this->wire()->input->post('settings_payload'));

        try {
            $profile = $this->settingsModule()->migrationService()->decode($json);
            $report = $this->settingsModule()->migrationService()->preview($profile);
            $this->wire()->session->setFor($this, self::SETTINGS_IMPORT_SESSION, [
                'json' => $json,
                'report' => $report->toArray(),
            ]);
            $this->audit(
                'settings',
                'settings_profile',
                'workspace-settings',
                'previewed',
                metadata: [
                    'fingerprint' => $report->fingerprint,
                    'changeCount' => $report->changeCount(),
                    'successful' => $report->successful(),
                ],
            );

            if ($report->successful()) {
                $this->message(sprintf(
                    $this->_('Settings profile checked: %d change(s) are ready for review.'),
                    $report->changeCount(),
                ));
            } else {
                $this->error($this->_('The settings profile contains validation errors.'));
            }
        } catch (\InvalidArgumentException $exception) {
            $this->wire()->session->setFor($this, self::SETTINGS_IMPORT_SESSION, [
                'report' => [
                    'successful' => false,
                    'changeCount' => 0,
                    'providers' => [],
                    'warnings' => [],
                    'errors' => [$exception->getMessage()],
                ],
            ]);
            $this->error($exception->getMessage());
        }

        $this->wire()->session->redirect('../settings-migration/');
    }

    public function ___executeSettingsApply(): void
    {
        $this->requirePost();
        $this->requireSettings();
        $this->requirePermission('kontor-settings-import');
        $stored = $this->wire()->session->getFor($this, self::SETTINGS_IMPORT_SESSION);
        $confirmed = (string) $this->wire()->input->post('confirm_apply') === '1';

        if (!is_array($stored) || !is_string($stored['json'] ?? null) || !$confirmed) {
            throw new WireException($this->_('Preview the profile and confirm the reviewed changes before importing.'));
        }

        $profile = $this->settingsModule()->migrationService()->decode($stored['json']);
        $fingerprint = $this->settingsModule()->migrationService()->fingerprint($profile);
        $submitted = $this->wire()->sanitizer->text((string) $this->wire()->input->post('fingerprint'));

        if (!hash_equals($fingerprint, $submitted)) {
            throw new WireException($this->_('The settings preview has changed. Run the preview again.'));
        }

        $previous = $this->organizationAuditSnapshot($this->organization());
        $report = $this->settingsModule()->migrationService()->apply($profile);
        $current = $this->organizationAuditSnapshot($this->organizationRepository()->require(
            $this->organizationUid()
        ));
        $this->audit(
            'settings',
            'settings_profile',
            'workspace-settings',
            'imported',
            previous: ['organization' => $previous],
            current: ['organization' => $current],
            metadata: [
                'fingerprint' => $report->fingerprint,
                'providerCount' => count($report->providers),
                'changeCount' => $report->changeCount(),
            ],
        );
        $this->wire()->session->setFor($this, self::SETTINGS_IMPORT_SESSION, null);
        $this->message(sprintf(
            $this->_('Settings imported successfully: %d change(s) applied.'),
            $report->changeCount(),
        ));
        $this->wire()->session->redirect('../settings-migration/');
    }

    public function ___executeQueue(): string
    {
        $this->requirePermission('kontor-queue-view');
        $this->requireQueue();
        $this->setPageTitle($this->_('Kontor · Queue'));
        $queues = $this->jobRepository()->queues();
        $queue = $this->wire()->sanitizer->text((string) $this->wire()->input->get('queue'));
        $status = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->get('status'),
            ['active', 'pending', 'reserved', 'completed', 'dead', 'cancelled']
        );
        $queue = $queue !== '' && in_array($queue, $queues, true) ? $queue : null;
        $pageSize = 25;
        $totalJobs = $this->jobRepository()->countMatching($queue, $status);
        $totalPages = max(1, (int) ceil($totalJobs / $pageSize));
        $page = min($totalPages, max(1, (int) $this->wire()->input->get('page')));

        return $this->renderTemplate('queue', [
            'jobs' => $this->jobRepository()->findRecent(
                $queue,
                $status,
                $pageSize,
                ($page - 1) * $pageSize,
            ),
            'counts' => $this->jobRepository()->summaryCounts(),
            'queues' => $queues,
            'selectedQueue' => $queue,
            'selectedStatus' => $status,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalJobs' => $totalJobs,
            'canCancelJobs' => $this->wire()->user->isSuperuser()
                || $this->wire()->user->hasPermission('kontor-queue-cancel'),
            'canRetryJobs' => $this->wire()->user->isSuperuser()
                || $this->wire()->user->hasPermission('kontor-queue-retry'),
        ]);
    }

    public function ___executeQueueAction(): void
    {
        $this->requirePost();
        $this->requireQueue();
        $action = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('action'),
            ['cancel', 'retry']
        );
        $this->requireAction($action, ['cancel', 'retry']);
        $uid = $this->wire()->sanitizer->text((string) $this->wire()->input->post('uid'));
        $job = $this->jobRepository()->find($uid);

        if ($job === null) {
            throw new WireException($this->_('Queue job was not found.'));
        }

        if ($action === 'cancel') {
            $this->requirePermission('kontor-queue-cancel');
            $changed = $this->jobRepository()->cancel($uid);
            $auditAction = 'cancelled';
            $message = $this->_('Pending job cancelled.');
        } else {
            $this->requirePermission('kontor-queue-retry');
            $changed = $this->jobRepository()->retryDead($uid);
            $auditAction = 'retried';
            $message = $this->_('Dead-letter job returned to the queue.');
        }

        if (!$changed) {
            throw new WireException($this->_('The job state changed before this action could be applied.'));
        }

        $this->audit(
            'queue',
            'job',
            $uid,
            $auditAction,
            previous: ['status' => (string) $job['status'], 'attempts' => (int) $job['attempts']],
            current: ['status' => $action === 'cancel' ? 'cancelled' : 'pending', 'attempts' => $action === 'cancel' ? (int) $job['attempts'] : 0],
            metadata: ['jobType' => (string) $job['job_type'], 'queue' => (string) $job['queue']],
        );
        $this->message($message);
        $this->wire()->session->redirect('../queue/?queue=' . rawurlencode((string) $job['queue']));
    }

    public function ___executeBackupCreate(): void
    {
        $this->requirePost();
        $this->requirePermission('kontor-backups-create');
        $component = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('component'),
            ['core', 'contacts', 'catalog']
        );
        $this->requireAction($component, ['core', 'contacts', 'catalog']);

        if ($component === 'catalog') {
            $this->requireCatalog();
        }
        $backup = $this->backupManager()->create(
            component: $component,
            kind: 'snapshot',
            organizationId: $this->organizationUid(),
            reason: 'Manual admin snapshot',
        );
        $this->audit(
            component: 'core',
            entityType: 'backup',
            entityUid: $backup->id,
            action: $backup->verified ? 'created' : 'verification_failed',
            metadata: [
                'backupComponent' => $component,
                'itemCount' => $backup->itemCount,
                'checksum' => $backup->checksum,
            ],
        );

        if ($backup->verified) {
            $this->message(sprintf(
                $this->_('Verified %s snapshot created with %d items.'),
                $component,
                $backup->itemCount
            ));
        } else {
            $this->error($this->_('Backup was created but verification failed.'));
        }

        $this->wire()->session->redirect('../backups/');
    }

    public function ___executeBackupDownload(): void
    {
        $this->requirePost();
        $this->requirePermission('kontor-backups-download');
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->post('id'));
        $path = $this->backupManager()->findById($id);

        if ($path === null) {
            throw new WireException($this->_('Backup was not found.'));
        }

        try {
            $metadata = json_decode(
                (string) file_get_contents($path . DIRECTORY_SEPARATOR . 'metadata.json'),
                true,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (\Throwable) {
            throw new WireException($this->_('Backup metadata is missing or invalid.'));
        }

        $component = (string) ($metadata['component'] ?? '');
        $kind = (string) ($metadata['kind'] ?? 'snapshot');

        if (
            !in_array($component, ['core', 'contacts', 'catalog'], true)
            || !$this->backupManager()->verify($path, $component, $this->organizationUid(), $kind)
        ) {
            throw new WireException($this->_('Backup verification failed; download was blocked.'));
        }

        $backupUid = substr($id, -26);

        try {
            Uid::fromString($backupUid);
        } catch (\InvalidArgumentException) {
            throw new WireException($this->_('Backup identifier is invalid.'));
        }

        $temporary = tempnam($this->wire()->config->paths->cache, 'kontor_backup_');

        if ($temporary === false) {
            throw new WireException($this->_('Could not create a temporary archive.'));
        }

        $archivePath = $temporary . '.zip';
        @unlink($temporary);
        register_shutdown_function(static function () use ($archivePath): void {
            if (is_file($archivePath)) {
                unlink($archivePath);
            }
        });
        $this->backupArchiveBuilder()->create($path, $archivePath);
        $this->audit(
            'core',
            'backup',
            $backupUid,
            'downloaded',
            metadata: [
                'backupId' => $id,
                'backupComponent' => $component,
                'sizeBytes' => filesize($archivePath) ?: 0,
            ],
        );
        wireSendFile($archivePath, [
            'forceDownload' => true,
            'downloadFilename' => $id . '.zip',
            'exit' => true,
        ]);
    }

    public function ___executeAddress(): void
    {
        $this->requirePost();
        $ownerType = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('owner_type'),
            ['contact', 'company']
        );
        $action = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('action'),
            ['add', 'delete']
        );
        $this->requireAction($ownerType, ['contact', 'company']);
        $this->requireAction($action, ['add', 'delete']);
        $ownerUid = $this->wire()->sanitizer->text((string) $this->wire()->input->post('owner_uid'));
        $owner = $ownerType === 'contact'
            ? $this->contactRepository()->require($ownerUid)
            : $this->companyRepository()->require($ownerUid);
        $this->requireSameOrganization($owner->organizationId);
        $this->requirePermission($ownerType === 'contact'
            ? 'kontor-contacts-contact-edit'
            : 'kontor-contacts-company-edit');

        if ($action === 'delete') {
            $addressUid = $this->wire()->sanitizer->text((string) $this->wire()->input->post('address_uid'));
            $address = $this->addressRepository()->find($addressUid);

            if (
                $address === null
                || $address->ownerType !== $ownerType
                || !hash_equals($address->ownerUid, $ownerUid)
                || !hash_equals($address->organizationId, $this->organizationUid())
            ) {
                throw new WirePermissionException($this->_('Address does not belong to this record.'));
            }

            $this->addressRepository()->delete($addressUid);
            $this->audit(
                'contacts',
                'address',
                $addressUid,
                'deleted',
                previous: $this->addressAuditSnapshot($address),
                metadata: ['ownerType' => $ownerType, 'ownerUid' => $ownerUid],
            );
            $this->message($this->_('Address removed.'));
        } else {
            $line1 = $this->wire()->sanitizer->text(trim((string) $this->wire()->input->post('line1')));
            $city = $this->wire()->sanitizer->text(trim((string) $this->wire()->input->post('city')));
            $countryCode = strtoupper(trim((string) $this->wire()->input->post('country_code')));

            if ($line1 === '' || $city === '' || preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
                $this->error($this->_('Street, city and a two-letter country code are required.'));
                $this->wire()->session->redirect('../' . $ownerType . '/?id=' . rawurlencode($ownerUid));
            }

            $addresses = $this->addressRepository()->forOwner($ownerType, $ownerUid);
            $addressType = $this->wire()->sanitizer->option(
                (string) $this->wire()->input->post('address_type'),
                ['billing', 'shipping', 'home', 'work', 'other']
            ) ?? 'billing';
            $address = Address::create(
                organizationId: $this->organizationUid(),
                ownerType: $ownerType,
                ownerUid: $ownerUid,
                line1: $line1,
                city: $city,
                countryCode: $countryCode,
                addressType: $addressType,
                line2: $this->nullablePostText('line2'),
                region: $this->nullablePostText('region'),
                postalCode: $this->nullablePostText('postal_code'),
                isPrimary: $addresses === [] || (bool) $this->wire()->input->post('is_primary'),
            );
            $this->addressRepository()->save($address);
            $this->audit(
                'contacts',
                'address',
                $address->uid->toString(),
                'created',
                current: $this->addressAuditSnapshot($address),
                metadata: ['ownerType' => $ownerType, 'ownerUid' => $ownerUid],
            );
            $this->message($this->_('Address added.'));
        }

        $this->wire()->session->redirect('../' . $ownerType . '/?id=' . rawurlencode($ownerUid));
    }

    public function ___executeTags(): void
    {
        $this->requirePost();
        $ownerType = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('owner_type'),
            ['contact', 'company']
        );
        $this->requireAction($ownerType, ['contact', 'company']);
        $ownerUid = $this->wire()->sanitizer->text((string) $this->wire()->input->post('owner_uid'));
        $owner = $ownerType === 'contact'
            ? $this->contactRepository()->require($ownerUid)
            : $this->companyRepository()->require($ownerUid);
        $this->requireSameOrganization($owner->organizationId);
        $this->requirePermission($ownerType === 'contact'
            ? 'kontor-contacts-contact-edit'
            : 'kontor-contacts-company-edit');
        $previousTags = $this->tagService()->tagsFor($this->organizationUid(), $ownerType, $ownerUid);
        $rawTags = explode(',', (string) $this->wire()->input->post('tags'));
        $tags = [];

        foreach (array_slice($rawTags, 0, 20) as $rawTag) {
            $tag = mb_substr($this->wire()->sanitizer->text(trim($rawTag)), 0, 50);

            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        $this->tagService()->setTags($this->organizationUid(), $ownerType, $ownerUid, $tags);
        $currentTags = $this->tagService()->tagsFor($this->organizationUid(), $ownerType, $ownerUid);
        $this->audit(
            'contacts',
            $ownerType,
            $ownerUid,
            'tags_updated',
            previous: ['tags' => $previousTags],
            current: ['tags' => $currentTags],
        );
        $this->message($this->_('Tags updated.'));
        $this->wire()->session->redirect('../' . $ownerType . '/?id=' . rawurlencode($ownerUid));
    }

    public function ___executeExport(): void
    {
        $entityType = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->get('entity'),
            ['contact', 'company', 'catalog_item']
        );
        $format = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->get('format'),
            ['csv', 'json', 'jsonl', 'xlsx']
        );
        $this->requireAction($entityType, ['contact', 'company', 'catalog_item']);
        $this->requireAction($format, ['csv', 'json', 'jsonl', 'xlsx']);
        $this->requireDataExchangeEntity($entityType);
        $this->requirePermission($entityType === 'catalog_item'
            ? 'kontor-catalog-export'
            : 'kontor-contacts-export');

        $temporary = tempnam($this->wire()->config->paths->cache, 'kontor_export_');

        if ($temporary === false) {
            throw new WireException($this->_('Could not create an export file.'));
        }

        $path = $temporary . '.' . $format;
        rename($temporary, $path);
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        $total = $this->exportManager()->run(
            entityType: $entityType,
            filters: [],
            fields: [],
            context: new ExportContext(
                organizationId: $this->organizationUid(),
                actorType: 'user',
                actorId: (string) $this->wire()->user->id,
            ),
            writer: (new FormatResolver())->writer($format),
            path: $path,
        );
        $date = (new \DateTimeImmutable())->format('Y-m-d');
        $filename = 'kontor-' . match ($entityType) {
            'contact' => 'contacts',
            'company' => 'companies',
            default => 'catalog-items',
        } . "-{$date}.{$format}";
        $this->audit(
            $entityType === 'catalog_item' ? 'catalog' : 'core',
            $entityType,
            'bulk',
            'exported',
            metadata: ['format' => $format, 'recordCount' => $total],
        );
        $this->wire()->log->save('kontor', "Exported {$total} {$entityType} records as {$format}.");
        wireSendFile($path, [
            'forceDownload' => true,
            'downloadFilename' => $filename,
            'exit' => true,
        ]);
    }

    public function ___executeImport(): string
    {
        $this->requirePermission('kontor-import');
        $this->setPageTitle($this->_('Kontor · Import preview'));
        $entityType = $this->wire()->sanitizer->option(
            (string) ($this->wire()->input->post('entity') ?: $this->wire()->input->get('entity')),
            ['contact', 'company', 'catalog_item']
        ) ?? 'contact';
        $this->requireDataExchangeEntity($entityType);
        $result = null;
        $filename = null;
        $previewToken = null;
        $backupId = null;
        $this->cleanupImportPreviews();

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            $this->requirePost();

            try {
                if ($this->wire()->input->post('commit_import')) {
                    [$result, $filename, $backupId] = $this->commitImportPreview(
                        $this->wire()->sanitizer->text((string) $this->wire()->input->post('preview_token'))
                    );
                    $entityType = $result->entityType;
                    $this->message(sprintf(
                        $this->_('Import completed: %d created, %d updated. Verified backup %s was created first.'),
                        $result->created,
                        $result->updated,
                        $backupId
                    ));
                } else {
                    [$path, $format, $filename] = $this->receiveImportFile();
                    $batchId = Uid::generate()->toString();

                    try {
                        $result = $this->importManager()->run(
                            entityType: $entityType,
                            reader: (new FormatResolver())->reader($format),
                            path: $path,
                            context: new ImportContext(
                                organizationId: $this->organizationUid(),
                                batchId: $batchId,
                                dryRun: true,
                                actorType: 'user',
                                actorId: (string) $this->wire()->user->id,
                            ),
                        );

                        if ($result->totalRows > 0 && $result->failed === 0) {
                            $previewToken = $this->storeImportPreview(
                                $batchId,
                                $path,
                                $format,
                                $filename,
                                $entityType,
                                $result->updated
                            );
                            $path = '';
                        }
                    } finally {
                        if ($path !== '' && is_file($path)) {
                            unlink($path);
                        }
                    }
                }
            } catch (\Throwable $exception) {
                $this->error($this->_('Import failed: ') . $exception->getMessage());
            }
        }

        return $this->renderTemplate('import', [
            'entityType' => $entityType,
            'result' => $result,
            'filename' => $filename,
            'previewToken' => $previewToken,
            'backupId' => $backupId,
            'availableEntityTypes' => $this->availableImportEntityTypes(),
            'backupLabel' => $entityType === 'catalog_item' ? 'Catalog' : 'Contacts',
        ]);
    }

    public function ___executeContactStatus(): void
    {
        $this->requirePost();
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->post('id'));
        $action = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('action'),
            ['archive', 'restore']
        );
        $this->requireAction($action, ['archive', 'restore']);
        $contact = $this->contactRepository()->require($id);
        $this->requireSameOrganization($contact->organizationId);
        $this->requirePermission('kontor-contacts-contact-archive');

        $action === 'restore'
            ? $this->contactRepository()->restore($id)
            : $this->contactRepository()->archive($id);
        $this->audit('contacts', 'contact', $id, $action === 'restore' ? 'restored' : 'archived');
        $this->message($action === 'restore' ? $this->_('Contact restored.') : $this->_('Contact archived.'));
        $this->wire()->session->redirect('../contacts/' . ($action === 'restore' ? '?archived=1' : ''));
    }

    public function ___executeCompanyStatus(): void
    {
        $this->requirePost();
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->post('id'));
        $action = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('action'),
            ['archive', 'restore']
        );
        $this->requireAction($action, ['archive', 'restore']);
        $company = $this->companyRepository()->require($id);
        $this->requireSameOrganization($company->organizationId);
        $this->requirePermission('kontor-contacts-company-archive');

        $action === 'restore'
            ? $this->companyRepository()->restore($id)
            : $this->companyRepository()->archive($id);
        $this->audit('contacts', 'company', $id, $action === 'restore' ? 'restored' : 'archived');
        $this->message($action === 'restore' ? $this->_('Company restored.') : $this->_('Company archived.'));
        $this->wire()->session->redirect('../companies/' . ($action === 'restore' ? '?archived=1' : ''));
    }

    public function ___executeRelationship(): void
    {
        $this->requirePost();
        $contactId = $this->wire()->sanitizer->text((string) $this->wire()->input->post('contact_id'));
        $companyId = $this->wire()->sanitizer->text((string) $this->wire()->input->post('company_id'));
        $action = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('action'),
            ['add', 'end']
        );
        $this->requireAction($action, ['add', 'end']);
        $contact = $this->contactRepository()->require($contactId);
        $company = $this->companyRepository()->require($companyId);
        $this->requireSameOrganization($contact->organizationId);
        $this->requireSameOrganization($company->organizationId);
        $this->requirePermission('kontor-contacts-contact-edit');

        if ($action === 'end') {
            $this->membershipRepository()->end($contactId, $companyId, new \DateTimeImmutable('today'));
            $this->audit(
                'contacts',
                'membership',
                $contactId,
                'ended',
                metadata: ['companyUid' => $companyId],
            );
            $this->message($this->_('Company relationship ended.'));
        } else {
            $role = $this->wire()->sanitizer->text((string) $this->wire()->input->post('role'));
            $department = $this->wire()->sanitizer->text((string) $this->wire()->input->post('department'));
            $this->membershipRepository()->save(new ContactCompanyMembership(
                organizationId: $this->organizationUid(),
                contactUid: $contactId,
                companyUid: $companyId,
                role: $role !== '' ? $role : $this->_('Member'),
                department: $department !== '' ? $department : null,
                isPrimary: false,
                startedAt: new \DateTimeImmutable('today'),
                endedAt: null,
            ));
            $this->audit(
                'contacts',
                'membership',
                $contactId,
                'created',
                current: ['role' => $role !== '' ? $role : $this->_('Member'), 'department' => $department ?: null],
                metadata: ['companyUid' => $companyId],
            );
            $this->message($this->_('Company relationship added.'));
        }

        $this->wire()->session->redirect('../contact/?id=' . rawurlencode($contactId));
    }

    public function ___executeComponents(): string
    {
        $this->requirePermission('kontor-components-view');
        $this->setPageTitle($this->_('Kontor · Components'));
        $query = $this->wire()->sanitizer->text((string) $this->wire()->input->get('q'));
        $status = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->get('status'),
            ['enabled', 'disabled', 'installed', 'uninstalled', 'attention']
        ) ?? '';
        $rows = $this->componentRegistry()->all();
        $builder = new ComponentOverviewBuilder();
        $runtimeInfo = [];
        $navigation = array_values($this->availableNavigationItems());
        $canConfigureModules = $this->wire()->user->isSuperuser()
            || $this->wire()->user->hasPermission('module-admin');

        foreach ($rows as $row) {
            $moduleName = $builder->moduleName((string) ($row['name'] ?? ''));
            $info = $this->wire()->modules->getModuleInfoVerbose($moduleName);
            $info = is_array($info) ? $info : [];
            $info['workspaceUrl'] = $this->componentWorkspaceUrl(
                (string) ($row['name'] ?? ''),
                $moduleName,
                (string) ($info['title'] ?? $moduleName),
                $navigation,
            );
            $info['settingsUrl'] = $canConfigureModules
                && (bool) ($info['configurable'] ?? false)
                && (bool) ($info['installed'] ?? false)
                    ? $this->wire()->modules->getModuleEditUrl($moduleName)
                    : '';
            $runtimeInfo[$moduleName] = $info;
        }
        $overview = $builder->build($rows, $runtimeInfo, $query, $status);

        return $this->renderTemplate('components', [
            'components' => $overview['components'],
            'counts' => $overview['counts'],
            'query' => $query,
            'selectedStatus' => $status,
            'canSyncComponents' => $this->wire()->user->isSuperuser()
                || $this->wire()->user->hasPermission('kontor-components-update'),
        ]);
    }

    /**
     * @param array<int, array{url: string, label: string, icon: string, permission?: string}> $navigation
     */
    private function componentWorkspaceUrl(
        string $componentName,
        string $moduleName,
        string $title,
        array $navigation,
    ): string {
        $baseUrl = $this->wire()->config->urls->admin . 'kontor/';
        if ($moduleName === 'Kontor') {
            foreach ($navigation as $item) {
                if ((string) ($item['url'] ?? '') === '') {
                    return $baseUrl;
                }
            }
        }

        $identifiers = array_filter(array_unique([
            $this->normalizeComponentNavigationName($componentName),
            $this->normalizeComponentNavigationName(
                str_starts_with($moduleName, 'Kontor') ? substr($moduleName, 6) : $moduleName
            ),
            $this->normalizeComponentNavigationName(
                str_starts_with($title, 'Kontor ') ? substr($title, 7) : $title
            ),
        ]));

        foreach ($navigation as $item) {
            if (in_array(
                $this->normalizeComponentNavigationName((string) ($item['label'] ?? '')),
                $identifiers,
                true,
            )) {
                return $baseUrl . ltrim((string) ($item['url'] ?? ''), '/');
            }
        }

        return '';
    }

    private function normalizeComponentNavigationName(string $value): string
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));

        return strlen($normalized) > 3 && str_ends_with($normalized, 's')
            ? substr($normalized, 0, -1)
            : $normalized;
    }

    public function ___executeComponentSync(): void
    {
        $this->requirePost();
        $this->requirePermission('kontor-components-update');
        $name = $this->wire()->sanitizer->text((string) $this->wire()->input->post('component'));
        $registered = $this->componentRegistry()->find($name);

        if ($registered === null) {
            throw new WireException($this->_('Component is not registered.'));
        }

        $moduleName = (new ComponentOverviewBuilder())->moduleName($name);
        $runtime = $this->wire()->modules->getModuleInfoVerbose($moduleName);

        if (
            !is_array($runtime)
            || !($runtime['installed'] ?? false)
            || (string) ($runtime['version'] ?? '') === ''
        ) {
            throw new WireException($this->_('The matching ProcessWire module is not installed.'));
        }

        $previous = [
            'version' => (string) ($registered['version'] ?? ''),
            'status' => (string) ($registered['status'] ?? ''),
        ];
        $version = (string) $runtime['version'];
        $this->componentRegistry()->markInstalled(
            $name,
            $version,
            (string) ($registered['source'] ?? $name),
            isset($registered['checksum']) ? (string) $registered['checksum'] : null,
        );
        $this->componentRegistry()->enable($name);
        $this->audit(
            'core',
            'component',
            $name,
            'synchronized',
            previous: $previous,
            current: ['version' => $version, 'status' => 'enabled'],
            metadata: ['moduleName' => $moduleName],
        );
        $this->message(sprintf(
            $this->_('%s synchronized with ProcessWire runtime version %s.'),
            (string) ($runtime['title'] ?? $moduleName),
            (string) ($runtime['versionStr'] ?? $version),
        ));
        $this->wire()->session->redirect('../components/');
    }
}

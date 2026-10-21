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
trait ProcessKontorCustomerTrait
{

    public function ___executeContacts(): string
    {
        $this->requirePermission('kontor-contacts-contact-view');
        $this->requireContacts();
        $this->setPageTitle($this->_('Kontor · Contacts'));
        $query = $this->wire()->sanitizer->text((string) $this->wire()->input->get('q'));
        $showArchived = (string) $this->wire()->input->get('archived') === '1';
        $status = $showArchived ? '' : ($this->wire()->sanitizer->option(
            (string) $this->wire()->input->get('status'),
            ['active', 'inactive']
        ) ?? '');
        $organizationUid = $this->organizationUid();
        $pageSize = 25;
        $totalRecords = $this->contactRepository()->countMatching(
            $organizationUid,
            $query,
            $showArchived,
            $status,
        );
        $totalPages = max(1, (int) ceil($totalRecords / $pageSize));
        $page = min($totalPages, max(1, (int) $this->wire()->input->get('page')));

        return $this->renderTemplate('contacts', [
            'contacts' => $showArchived
                ? $this->contactRepository()->findArchived(
                    $organizationUid,
                    $query,
                    $pageSize,
                    ($page - 1) * $pageSize,
                    $status,
                )
                : $this->contactRepository()->findAll(
                    $organizationUid,
                    $query,
                    $pageSize,
                    ($page - 1) * $pageSize,
                    $status,
                ),
            'query' => $query,
            'selectedStatus' => $status,
            'showArchived' => $showArchived,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalRecords' => $totalRecords,
            'contactCounts' => [
                'all' => $this->contactRepository()->countMatching($organizationUid),
                'active' => $this->contactRepository()->countMatching(
                    $organizationUid,
                    status: 'active',
                ),
                'inactive' => $this->contactRepository()->countMatching(
                    $organizationUid,
                    status: 'inactive',
                ),
                'archived' => $this->contactRepository()->countMatching(
                    $organizationUid,
                    archived: true,
                ),
            ],
        ]);
    }

    public function ___executeContact(): string
    {
        $this->requireContacts();
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->get('id'));
        $contact = $id !== '' ? $this->contactRepository()->require($id) : null;
        $this->requirePermission($contact === null
            ? 'kontor-contacts-contact-create'
            : 'kontor-contacts-contact-edit');
        $this->setPageTitle($contact === null
            ? $this->_('Kontor · New contact')
            : sprintf($this->_('Kontor · %s'), $contact->displayName));

        $isNew = $contact === null;
        $previous = $contact === null ? null : $this->contactAuditSnapshot($contact);
        $form = $this->buildContactForm($contact);
        $duplicates = [];
        $intakeFields = $this->crmIntakeFields('contact');
        $intakeValues = $contact === null ? [] : $this->crmIntakeValues('contact', $contact->uid->toString());
        $intakeAnswers = null;
        $intakeError = '';

        if ($this->wire()->input->post('submit_save')) {
            $this->requirePost();
            $form->processInput($this->wire()->input->post);
            try {
                $intakeAnswers = $this->postedCrmIntakeAnswers('contact', $intakeFields);
                $intakeValues = $intakeAnswers;
            } catch (\InvalidArgumentException $exception) {
                $intakeError = $exception->getMessage();
            }

            if (!$form->getErrors() && $intakeError === '') {
                if ($contact === null) {
                    $duplicates = $this->duplicateDetector()->findDuplicates(
                        $this->organizationUid(),
                        $this->formValue($form, 'email'),
                        $this->formValue($form, 'phone')
                    );
                }

                if ($duplicates !== [] && !$this->wire()->input->post('confirm_duplicate')) {
                    $this->addDuplicateConfirmation($form);
                } else {
                    $contact = $this->saveContactFromForm($form, $contact);
                    if ($intakeAnswers !== null) {
                        $this->saveCrmIntakeAnswers('contact', $contact->uid->toString(), $intakeAnswers);
                    }
                    $this->audit(
                        component: 'contacts',
                        entityType: 'contact',
                        entityUid: $contact->uid->toString(),
                        action: $isNew ? 'created' : 'updated',
                        previous: $previous,
                        current: $this->contactAuditSnapshot($contact),
                    );
                    $this->message($this->_('Contact saved.'));
                    $this->wire()->session->redirect('../contact/?id=' . rawurlencode($contact->uid->toString()));
                }
            }
        }

        $relationships = $contact === null ? [] : $this->contactRelationships($contact);
        $aiSummary = $contact === null
            ? null
            : $this->wire()->session->get('kontorContactAISummary:' . $contact->uid->toString());
        if ($contact !== null) {
            $this->wire()->session->set('kontorContactAISummary:' . $contact->uid->toString(), null);
        }

        $formValues = [
            'display_name' => $contact?->displayName ?? '',
            'first_name' => $contact?->firstName ?? '',
            'last_name' => $contact?->lastName ?? '',
            'email' => $contact?->email ?? '',
            'phone' => $contact?->phone ?? '',
            'mobile' => $contact?->mobile ?? '',
            'job_title' => $contact?->jobTitle ?? '',
            'status' => $contact?->status ?? 'active',
            'notes' => $contact?->notes ?? '',
        ];
        if ($this->wire()->input->post('submit_save')) {
            foreach (array_keys($formValues) as $fieldName) {
                $formValues[$fieldName] = (string) $this->wire()->input->post($fieldName);
            }
        }

        return $this->renderTemplate('entity-form', [
            'form' => $form,
            'formValues' => $formValues,
            'intakeFields' => $intakeFields,
            'intakeValues' => $intakeValues,
            'intakeError' => $intakeError,
            'backUrl' => '../contacts/',
            'backLabel' => $this->_('Back to contacts'),
            'eyebrow' => $this->_('Contacts'),
            'title' => $contact?->displayName ?: $this->_('Create contact'),
            'description' => $contact === null
                ? $this->_('Add a person to your shared customer directory.')
                : $this->_('Keep identity, communication and assignment details in one place.'),
            'entity' => $contact,
            'entityType' => 'contact',
            'relationships' => $relationships,
            // The repository lists most recently updated first, which is right
            // for the companies page and wrong for a dropdown: a person
            // looking for Bromley expects A to Z.
            'availableCompanies' => $contact === null
                ? []
                : (static function (array $companies): array {
                    usort($companies, static fn ($a, $b): int => strcasecmp($a->legalName, $b->legalName));
                    return $companies;
                })($this->companyRepository()->findAll($this->organizationUid(), '', 250)),
            'addresses' => $contact === null
                ? []
                : $this->addressRepository()->forOwner('contact', $contact->uid->toString()),
            'duplicates' => $duplicates,
            'tags' => $contact === null
                ? []
                : $this->tagService()->tagsFor(
                    $this->organizationUid(),
                    'contact',
                    $contact->uid->toString()
                ),
            'workspace' => $contact === null
                ? $this->emptyCustomerWorkspace()
                : $this->customerWorkspace('contact', $contact->uid->toString()),
            'aiReady' => $this->aiReady(),
            'aiSummary' => is_array($aiSummary) ? $aiSummary : null,
        ]);
    }

    public function ___executeContactAISummary(): void
    {
        $this->requirePost();
        $this->requireContacts();
        $this->requireAI();
        $this->requirePermission('kontor-contacts-contact-edit');
        $this->requirePermission('kontor-ai-action-approve');
        $uid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('contact_uid')
        );
        $contact = $this->contactRepository()->require($uid);
        $this->requireSameOrganization($contact->organizationId);
        $relationships = $this->contactRelationships($contact);
        $tags = $this->tagService()->tagsFor(
            $contact->organizationId,
            'contact',
            $contact->uid->toString(),
        );
        $text = implode("\n", array_filter([
            'Contact: ' . $contact->displayName,
            $contact->email !== null ? 'Email: ' . $contact->email : null,
            $contact->phone !== null ? 'Phone: ' . $contact->phone : null,
            $contact->jobTitle !== null ? 'Job title: ' . $contact->jobTitle : null,
            $contact->notes !== null ? 'Notes: ' . $contact->notes : null,
            $tags !== [] ? 'Tags: ' . implode(', ', $tags) : null,
            $relationships !== [] ? 'Companies: ' . implode(', ', array_map(
                static fn (array $relationship): string => $relationship['entity']->legalName,
                $relationships,
            )) : null,
        ]));
        $simulate = (bool) $this->wire()->input->post('simulate');
        $gateway = $simulate
            ? $this->aiModule()->previewGateway()
            : $this->aiModule()->gateway();
        $response = (new \Kontor\AI\Application\SummaryService($gateway))->summarize(
            $contact->organizationId,
            $text,
            (string) $this->wire()->user->id,
        );
        if (!$response->success) {
            throw new WireException(sprintf(
                $this->_('Contact summary failed: %s'),
                $response->errorMessage ?? $this->_('no AI provider is available'),
            ));
        }
        $this->wire()->session->set('kontorContactAISummary:' . $uid, [
            'summary' => (string) ($response->output['summary'] ?? ''),
            'characters' => (int) ($response->output['characters'] ?? mb_strlen($text)),
            'simulated' => $simulate,
        ]);
        $this->audit('ai', 'contact', $uid, 'summarized', metadata: [
            'simulated' => $simulate,
            'characters' => (int) ($response->output['characters'] ?? mb_strlen($text)),
        ]);
        $this->message($simulate
            ? $this->_('Local AI contact brief generated.')
            : $this->_('AI contact brief generated.'));
        $this->wire()->session->redirect('../contact/?id=' . rawurlencode($uid));
    }

    public function ___executeCompanies(): string
    {
        $this->requirePermission('kontor-contacts-company-view');
        $this->requireContacts();
        $this->setPageTitle($this->_('Kontor · Companies'));
        $query = $this->wire()->sanitizer->text((string) $this->wire()->input->get('q'));
        $showArchived = (string) $this->wire()->input->get('archived') === '1';
        $status = $showArchived ? '' : ($this->wire()->sanitizer->option(
            (string) $this->wire()->input->get('status'),
            ['active', 'inactive']
        ) ?? '');
        $organizationUid = $this->organizationUid();
        $pageSize = 25;
        $totalRecords = $this->companyRepository()->countMatching(
            $organizationUid,
            $query,
            $showArchived,
            $status,
        );
        $totalPages = max(1, (int) ceil($totalRecords / $pageSize));
        $page = min($totalPages, max(1, (int) $this->wire()->input->get('page')));

        return $this->renderTemplate('companies', [
            'companies' => $showArchived
                ? $this->companyRepository()->findArchived(
                    $organizationUid,
                    $query,
                    $pageSize,
                    ($page - 1) * $pageSize,
                    $status,
                )
                : $this->companyRepository()->findAll(
                    $organizationUid,
                    $query,
                    $pageSize,
                    ($page - 1) * $pageSize,
                    $status,
                ),
            'query' => $query,
            'selectedStatus' => $status,
            'showArchived' => $showArchived,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalRecords' => $totalRecords,
        ]);
    }

    public function ___executeCrm(): string
    {
        $this->requirePermission('kontor-crm-lead-view');
        $this->requireCrm();
        $this->setPageTitle($this->_('Kontor · CRM'));
        $query = $this->wire()->sanitizer->text((string) $this->wire()->input->get('q'));
        $status = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->get('status'),
            ['new', 'contacted', 'qualified', 'converted', 'lost']
        );
        $showArchived = (string) $this->wire()->input->get('archived') === '1';
        /** @var KontorCRM $crm */
        $crm = $this->wire()->modules->get('KontorCRM');
        $repository = $crm->leadRepository();
        $organizationUid = $this->organizationUid();
        $pageSize = 25;
        $totalRecords = $repository->countMatching(
            $organizationUid,
            $query,
            $status,
            $showArchived,
        );
        $totalPages = max(1, (int) ceil($totalRecords / $pageSize));
        $page = min($totalPages, max(1, (int) $this->wire()->input->get('page')));
        $leads = $repository->findMatching(
            $organizationUid,
            $query,
            $status,
            $showArchived,
            $pageSize,
            ($page - 1) * $pageSize,
        );
        $canViewContact = $this->contactsReady() && $this->can('kontor-contacts-contact-view');
        $canViewCompany = $this->contactsReady() && $this->can('kontor-contacts-company-view');
        $customerLabels = [];
        if ($canViewContact) {
            foreach ($this->contactRepository()->findAll($organizationUid, limit: 250) as $contact) {
                $customerLabels['contact:' . $contact->uid->toString()] = $contact->displayName;
            }
        }
        if ($canViewCompany) {
            foreach ($this->companyRepository()->findAll($organizationUid, limit: 250) as $company) {
                $customerLabels['company:' . $company->uid->toString()] = $company->legalName;
            }
        }

        return $this->renderTemplate('crm', [
            'leads' => $leads,
            'query' => $query,
            'selectedStatus' => $status,
            'showArchived' => $showArchived,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalRecords' => $totalRecords,
            'leadCounts' => [
                'all' => $repository->countMatching($organizationUid),
                'new' => $repository->countMatching($organizationUid, status: 'new'),
                'contacted' => $repository->countMatching($organizationUid, status: 'contacted'),
                'qualified' => $repository->countMatching($organizationUid, status: 'qualified'),
                'converted' => $repository->countMatching($organizationUid, status: 'converted'),
                'lost' => $repository->countMatching($organizationUid, status: 'lost'),
                'archived' => $repository->countMatching($organizationUid, archived: true),
            ],
            'customerLabels' => $customerLabels,
            'canViewContact' => $canViewContact,
            'canViewCompany' => $canViewCompany,
            'canViewDeals' => $this->can('kontor-crm-deal-view'),
            'canCreateLead' => $this->can('kontor-crm-lead-create'),
            'canArchiveLead' => $this->can('kontor-crm-lead-archive'),
            'crmIntakeReady' => $this->crmIntakeReady(),
        ]);
    }

    public function ___executeCrmIntake(): string
    {
        $this->requirePermission('kontor-crm-lead-view');
        if (!$this->crmIntakeReady()) {
            throw new Wire404Exception($this->_('CRM intake profiles are not installed.'));
        }
        $this->setPageTitle($this->_('CRM intake profile'));
        $profile = $this->crmIntakeModule()->profileRepository()->defaultForOrganization(
            $this->organizationUid(),
        );

        return $this->renderTemplate('crm-intake', [
            'profile' => $profile,
            'settingsReady' => $this->wire()->modules->isInstalled('KontorSettings'),
            'canManage' => $this->can('kontor-crm-intake-admin')
                && ($this->can('kontor-settings-export') || $this->can('kontor-settings-import')),
        ]);
    }

    public function ___executeCrmLead(): string
    {
        $this->requireCrm();
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->get('id'));
        /** @var KontorCRM $crm */
        $crm = $this->wire()->modules->get('KontorCRM');
        $lead = $id !== '' ? $crm->leadRepository()->require($id) : null;

        if ($lead !== null) {
            $this->requireSameOrganization($lead->organizationId);
        }

        $this->requirePermission($lead === null
            ? 'kontor-crm-lead-create'
            : 'kontor-crm-lead-edit');
        $this->setPageTitle($lead === null
            ? $this->_('Kontor · New lead')
            : sprintf($this->_('Kontor · %s'), $lead->title));
        $values = [
            'title' => $lead?->title ?? '',
            'contactUid' => $lead?->contactUid ?? ($lead === null
                ? $this->wire()->sanitizer->text((string) $this->wire()->input->get('contact'))
                : ''),
            'companyUid' => $lead?->companyUid ?? ($lead === null
                ? $this->wire()->sanitizer->text((string) $this->wire()->input->get('company'))
                : ''),
            'source' => $lead?->source ?? '',
            'status' => $lead?->status ?? 'new',
            'priority' => $lead?->priority ?? 'medium',
            'estimatedAmount' => $lead?->estimatedValue !== null
                ? number_format($lead->estimatedValue->amountMinor() / 100, 2, '.', '')
                : '',
            'currency' => $lead?->estimatedValue?->currencyCode() ?? 'EUR',
            'nextActionAt' => $lead?->nextActionAt?->format('Y-m-d\TH:i') ?? '',
            'description' => $lead?->description ?? '',
        ];
        $error = '';
        $intakeFields = $this->crmIntakeFields('lead');
        $intakeValues = $lead === null ? [] : $this->crmIntakeValues('lead', $lead->uid->toString());
        $intakeAnswers = null;

        if ($this->wire()->input->post('submit_save')) {
            $this->requirePost();
            $allowedStatuses = $lead?->isConverted()
                ? ['converted']
                : ['new', 'contacted', 'qualified', 'lost'];
            $values = [
                'title' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('title')),
                'contactUid' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('contact_uid')),
                'companyUid' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('company_uid')),
                'source' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('source')),
                'status' => $this->wire()->sanitizer->option(
                    (string) $this->wire()->input->post('status'),
                    $allowedStatuses
                ) ?? 'new',
                'priority' => $this->wire()->sanitizer->option(
                    (string) $this->wire()->input->post('priority'),
                    ['low', 'medium', 'high', 'urgent']
                ) ?? 'medium',
                'estimatedAmount' => $this->wire()->sanitizer->text(
                    (string) $this->wire()->input->post('estimated_amount')
                ),
                'currency' => strtoupper($this->wire()->sanitizer->text(
                    (string) $this->wire()->input->post('currency')
                )),
                'nextActionAt' => $this->wire()->sanitizer->text(
                    (string) $this->wire()->input->post('next_action_at')
                ),
                'description' => $this->wire()->sanitizer->textarea(
                    (string) $this->wire()->input->post('description')
                ),
            ];

            try {
                $intakeAnswers = $this->postedCrmIntakeAnswers('lead', $intakeFields);
                $intakeValues = $intakeAnswers;
                $values['source'] = $this->crmIntakeBoundValue(
                    $intakeFields,
                    $intakeAnswers ?? [],
                    'source',
                ) ?? $values['source'];
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }

            if ($values['title'] === '') {
                $error = $this->_('Lead title is required.');
            } elseif ($values['estimatedAmount'] !== ''
                && !is_numeric(str_replace(',', '.', $values['estimatedAmount']))) {
                $error = $this->_('Estimated value must be a number.');
            } elseif (preg_match('/^[A-Z]{3}$/', $values['currency']) !== 1) {
                $error = $this->_('Currency must be a three-letter code.');
            }

            if ($error === '' && $values['contactUid'] !== '') {
                $contact = $this->contactRepository()->find($values['contactUid']);
                if ($contact === null || !hash_equals($this->organizationUid(), $contact->organizationId)) {
                    $error = $this->_('Selected contact is invalid.');
                }
            }

            if ($error === '' && $values['companyUid'] !== '') {
                $company = $this->companyRepository()->find($values['companyUid']);
                if ($company === null || !hash_equals($this->organizationUid(), $company->organizationId)) {
                    $error = $this->_('Selected company is invalid.');
                }
            }

            $estimatedValue = null;
            $nextActionAt = null;

            if ($error === '' && $values['estimatedAmount'] !== '') {
                $estimatedValue = Money::ofMinor(
                    (int) round((float) str_replace(',', '.', $values['estimatedAmount']) * 100),
                    $values['currency']
                );
            }

            if ($error === '' && $values['nextActionAt'] !== '') {
                try {
                    $nextActionAt = new \DateTimeImmutable($values['nextActionAt']);
                } catch (\Throwable) {
                    $error = $this->_('Next action date is invalid.');
                }
            }

            if ($error === '') {
                $isNew = $lead === null;
                $lead ??= Lead::create($this->organizationUid(), $values['title']);
                $lead->title = $values['title'];
                $lead->contactUid = $values['contactUid'] ?: null;
                $lead->companyUid = $values['companyUid'] ?: null;
                $lead->source = $values['source'] ?: null;
                $lead->status = $values['status'];
                $lead->priority = $values['priority'];
                $lead->estimatedValue = $estimatedValue;
                $lead->nextActionAt = $nextActionAt;
                $lead->description = $values['description'] ?: null;
                $crm->leadRepository()->save($lead);
                if ($intakeAnswers !== null) {
                    $this->saveCrmIntakeAnswers('lead', $lead->uid->toString(), $intakeAnswers);
                }
                $this->audit(
                    'crm',
                    'lead',
                    $lead->uid->toString(),
                    $isNew ? 'created' : 'updated',
                    current: [
                        'title' => $lead->title,
                        'status' => $lead->status,
                        'priority' => $lead->priority,
                    ],
                );
                $this->message($this->_('Lead saved.'));
                $this->wire()->session->redirect(
                    '../crm-lead/?id=' . rawurlencode($lead->uid->toString())
                );
            }
        }

        $organizationUid = $this->organizationUid();
        $canViewContact = $this->contactsReady() && $this->can('kontor-contacts-contact-view');
        $canViewCompany = $this->contactsReady() && $this->can('kontor-contacts-company-view');
        $defaultPipeline = $lead !== null && $lead->status === 'qualified'
            ? $crm->pipelineRepository()->defaultForEntityType($organizationUid, 'deal')
            : null;
        $firstDealStage = $defaultPipeline !== null
            ? $crm->stageRepository()->firstOpenStage($defaultPipeline->uid->toString())
            : null;

        return $this->renderTemplate('crm-lead', [
            'lead' => $lead,
            'values' => $values,
            'error' => $error,
            'intakeFields' => $intakeFields,
            'intakeValues' => $intakeValues,
            'contacts' => $canViewContact
                ? $this->contactRepository()->findAll($organizationUid, limit: 250)
                : [],
            'companies' => $canViewCompany
                ? $this->companyRepository()->findAll($organizationUid, limit: 250)
                : [],
            'canViewContact' => $canViewContact,
            'canViewCompany' => $canViewCompany,
            'canCreateContact' => $this->contactsReady() && $this->can('kontor-contacts-contact-create'),
            'canCreateCompany' => $this->contactsReady() && $this->can('kontor-contacts-company-create'),
            'canViewDeal' => $this->can('kontor-crm-deal-view'),
            'canConvertLead' => $lead !== null
                && $lead->status === 'qualified'
                && $lead->isQualifiedForConversion()
                && $defaultPipeline !== null
                && $firstDealStage !== null
                && $this->can('kontor-crm-lead-convert')
                && $this->can('kontor-crm-deal-create'),
            'canConfigurePipeline' => $this->can('kontor-crm-pipeline-admin'),
            'conversionNeedsCustomer' => $lead !== null
                && $lead->status === 'qualified'
                && !$lead->isQualifiedForConversion(),
            'conversionNeedsPipeline' => $lead !== null
                && $lead->status === 'qualified'
                && $lead->isQualifiedForConversion()
                && ($defaultPipeline === null || $firstDealStage === null),
        ]);
    }

    public function ___executeCrmLeadConvert(): void
    {
        $this->requirePost();
        $this->requireCrm();
        $this->requirePermission('kontor-crm-lead-edit');
        $this->requirePermission('kontor-crm-lead-convert');
        $this->requirePermission('kontor-crm-deal-create');
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->post('id'));
        /** @var KontorCRM $crm */
        $crm = $this->wire()->modules->get('KontorCRM');
        $lead = $crm->leadRepository()->require($id);
        $this->requireSameOrganization($lead->organizationId);

        if ($lead->isConverted() && $lead->convertedDealUid !== null) {
            $this->message($this->_('This lead is already connected to a deal.'));
            $this->wire()->session->redirect('../crm-deal/?id=' . rawurlencode($lead->convertedDealUid));
        }
        if ($lead->status !== 'qualified' || !$lead->isQualifiedForConversion()) {
            $this->error($this->_('Qualify the lead and connect a contact or company before conversion.'));
            $this->wire()->session->redirect('../crm-lead/?id=' . rawurlencode($id));
        }
        $pipeline = $crm->pipelineRepository()->defaultForEntityType($this->organizationUid(), 'deal');
        if ($pipeline === null || $crm->stageRepository()->firstOpenStage($pipeline->uid->toString()) === null) {
            $this->error($this->_('Configure a default deal pipeline with an open stage before conversion.'));
            $this->wire()->session->redirect('../crm-lead/?id=' . rawurlencode($id));
        }

        $dealUid = $crm->crmService()->convertLead($id, (string) $this->wire()->user->id);
        if ($this->crmIntakeReady()) {
            $this->crmIntakeModule()->service()->copyAnswers(
                $this->organizationUid(),
                'lead',
                $id,
                'deal',
                $dealUid,
            );
        }
        $this->audit(
            'crm',
            'lead',
            $id,
            'converted',
            previous: ['status' => 'qualified'],
            current: ['status' => 'converted'],
            metadata: ['dealUid' => $dealUid],
        );
        $this->message($this->_('Lead converted to a deal.'));
        $this->wire()->session->redirect('../crm-deal/?id=' . rawurlencode($dealUid));
    }

    public function ___executeCrmLeadAction(): void
    {
        $this->requirePost();
        $this->requireCrm();
        $this->requirePermission('kontor-crm-lead-archive');
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->post('id'));
        $action = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('action'),
            ['archive', 'restore']
        );
        $this->requireAction($action, ['archive', 'restore']);
        /** @var KontorCRM $crm */
        $crm = $this->wire()->modules->get('KontorCRM');
        $lead = $crm->leadRepository()->require($id);
        $this->requireSameOrganization($lead->organizationId);
        $action === 'restore'
            ? $crm->leadRepository()->restore($id)
            : $crm->leadRepository()->archive($id);
        $this->audit('crm', 'lead', $id, $action === 'restore' ? 'restored' : 'archived');
        $this->message($action === 'restore' ? $this->_('Lead restored.') : $this->_('Lead archived.'));
        $parameters = array_filter([
            'q' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('return_q')),
            'status' => $this->wire()->sanitizer->option(
                (string) $this->wire()->input->post('return_status'),
                ['new', 'contacted', 'qualified', 'converted', 'lost']
            ),
            'archived' => (string) $this->wire()->input->post('return_archived') === '1' ? 1 : null,
        ], static fn (string|int|null $value): bool => $value !== null && $value !== '');
        $this->wire()->session->redirect(
            '../crm/' . ($parameters === [] ? '' : '?' . http_build_query($parameters))
        );
    }

    public function ___executeCrmDeals(): string
    {
        $this->requireCrm();
        $this->requirePermission('kontor-crm-deal-view');
        $this->setPageTitle($this->_('Kontor · CRM deals'));
        /** @var KontorCRM $crm */
        $crm = $this->wire()->modules->get('KontorCRM');
        $pipelines = $crm->pipelineRepository()->forOrganization($this->organizationUid());
        $pipelineId = $this->wire()->sanitizer->text((string) $this->wire()->input->get('pipeline'));
        $pipeline = null;

        if ($pipelineId !== '') {
            $pipeline = $crm->pipelineRepository()->require($pipelineId);
            $this->requireSameOrganization($pipeline->organizationId);
        } elseif ($pipelines !== []) {
            $pipeline = $pipelines[0];
        }

        $columns = $pipeline !== null
            ? $crm->kanbanBoard()->board($pipeline->uid->toString())
            : [];

        return $this->renderTemplate('crm-deals', [
            'pipelines' => $pipelines,
            'pipeline' => $pipeline,
            'columns' => $columns,
            'query' => trim($this->wire()->sanitizer->text((string) $this->wire()->input->get('q'))),
            'customerLabels' => $this->salesCustomerLabels(),
            'canViewLeads' => $this->can('kontor-crm-lead-view'),
            'canCreateDeal' => $this->can('kontor-crm-deal-create'),
            'canConfigurePipeline' => $this->can('kontor-crm-pipeline-admin'),
            'canMoveDeals' => $this->can('kontor-crm-deal-move'),
            'canViewContact' => $this->can('kontor-contacts-contact-view'),
            'canViewCompany' => $this->can('kontor-contacts-company-view'),
        ]);
    }

    public function ___executeCrmPipeline(): string
    {
        $this->requireCrm();
        $this->requirePermission('kontor-crm-pipeline-admin');
        $this->setPageTitle($this->_('Kontor · New CRM pipeline'));
        /** @var KontorCRM $crm */
        $crm = $this->wire()->modules->get('KontorCRM');
        $values = [
            'name' => '',
            'isDefault' => $crm->pipelineRepository()->forOrganization($this->organizationUid()) === [],
        ];
        $error = '';

        if ($this->wire()->input->post('submit_save')) {
            $this->requirePost();
            $values = [
                'name' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('name')),
                'isDefault' => (string) $this->wire()->input->post('is_default') === '1',
            ];

            if ($values['name'] === '') {
                $error = $this->_('Pipeline name is required.');
            }

            if ($error === '') {
                $pipeline = Pipeline::create(
                    $this->organizationUid(),
                    $values['name'],
                    isDefault: $values['isDefault'],
                );
                $crm->pipelineRepository()->save($pipeline);
                $stages = [
                    ['incoming', 'Incoming', 10, 'open', '#6b7280'],
                    ['qualified', 'Qualified', 30, 'open', '#2563eb'],
                    ['proposal', 'Proposal', 65, 'open', '#7c3aed'],
                    ['won', 'Won', 100, 'won', '#15803d'],
                    ['lost', 'Lost', 0, 'lost', '#b91c1c'],
                ];
                foreach ($stages as $sortOrder => [$key, $label, $probability, $stateType, $color]) {
                    $crm->stageRepository()->save(Stage::create(
                        $pipeline->uid->toString(),
                        $key,
                        ['en' => $label],
                        $probability,
                        $sortOrder + 1,
                        $stateType,
                        $color,
                    ));
                }
                $this->audit(
                    'crm',
                    'pipeline',
                    $pipeline->uid->toString(),
                    'created',
                    current: ['name' => $pipeline->name, 'stages' => count($stages)],
                );
                $this->message($this->_('Pipeline created with five standard stages.'));
                $this->wire()->session->redirect(
                    '../crm-deals/?pipeline=' . rawurlencode($pipeline->uid->toString())
                );
            }
        }

        return $this->renderTemplate('crm-pipeline', [
            'values' => $values,
            'error' => $error,
        ]);
    }

    public function ___executeCrmDeal(): string
    {
        $this->requireCrm();
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->get('id'));
        /** @var KontorCRM $crm */
        $crm = $this->wire()->modules->get('KontorCRM');
        $deal = $id !== '' ? $crm->dealRepository()->require($id) : null;

        if ($deal !== null) {
            $this->requireSameOrganization($deal->organizationId);
        }

        $this->requirePermission($deal === null
            ? 'kontor-crm-deal-create'
            : 'kontor-crm-deal-view');
        $canEdit = $deal === null || $this->can('kontor-crm-deal-edit');
        $pipelines = $crm->pipelineRepository()->forOrganization($this->organizationUid());
        $requestedPipeline = $this->wire()->sanitizer->text(
            (string) ($this->wire()->input->post('pipeline_uid') ?: $this->wire()->input->get('pipeline'))
        );
        $pipelineUid = $deal?->pipelineUid
            ?? ($requestedPipeline !== ''
                ? $requestedPipeline
                : (($pipelines[0] ?? null)?->uid->toString() ?? ''));
        $stages = $pipelineUid !== '' ? $crm->stageRepository()->forPipeline($pipelineUid) : [];
        $values = [
            'title' => $deal?->title ?? '',
            'pipelineUid' => $pipelineUid,
            'stageUid' => $deal?->stageUid ?? ($stages[0]?->uid->toString() ?? ''),
            'contactUid' => $deal?->contactUid ?? ($deal === null
                ? $this->wire()->sanitizer->text((string) $this->wire()->input->get('contact'))
                : ''),
            'companyUid' => $deal?->companyUid ?? ($deal === null
                ? $this->wire()->sanitizer->text((string) $this->wire()->input->get('company'))
                : ''),
            'source' => $deal?->source ?? '',
            'valueAmount' => $deal?->value !== null
                ? number_format($deal->value->amountMinor() / 100, 2, '.', '')
                : '',
            'currency' => $deal?->value?->currencyCode() ?? 'EUR',
            'probability' => $deal?->probability !== null ? (string) $deal->probability : '',
            'expectedCloseDate' => $deal?->expectedCloseDate?->format('Y-m-d') ?? '',
            'description' => $deal?->description ?? '',
        ];
        $error = '';
        $intakeFields = $this->crmIntakeFields('deal');
        $intakeValues = $deal === null ? [] : $this->crmIntakeValues('deal', $deal->uid->toString());
        $intakeAnswers = null;

        if ($this->wire()->input->post('submit_save')) {
            $this->requirePost();
            $this->requirePermission($deal === null
                ? 'kontor-crm-deal-create'
                : 'kontor-crm-deal-edit');
            $values = [
                'title' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('title')),
                'pipelineUid' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('pipeline_uid')),
                'stageUid' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('stage_uid')),
                'contactUid' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('contact_uid')),
                'companyUid' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('company_uid')),
                'source' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('source')),
                'valueAmount' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('value_amount')),
                'currency' => strtoupper($this->wire()->sanitizer->text(
                    (string) $this->wire()->input->post('currency')
                )),
                'probability' => $this->wire()->sanitizer->text((string) $this->wire()->input->post('probability')),
                'expectedCloseDate' => $this->wire()->sanitizer->text(
                    (string) $this->wire()->input->post('expected_close_date')
                ),
                'description' => $this->wire()->sanitizer->textarea(
                    (string) $this->wire()->input->post('description')
                ),
            ];

            try {
                $intakeAnswers = $this->postedCrmIntakeAnswers('deal', $intakeFields);
                $intakeValues = $intakeAnswers;
                $values['source'] = $this->crmIntakeBoundValue(
                    $intakeFields,
                    $intakeAnswers ?? [],
                    'source',
                ) ?? $values['source'];
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }

            $pipeline = $crm->pipelineRepository()->find($values['pipelineUid']);
            $stage = $crm->stageRepository()->find($values['stageUid']);
            if ($values['title'] === '') {
                $error = $this->_('Deal title is required.');
            } elseif ($pipeline === null || !hash_equals($this->organizationUid(), $pipeline->organizationId)) {
                $error = $this->_('Selected pipeline is invalid.');
            } elseif ($deal !== null && !hash_equals($deal->pipelineUid, $values['pipelineUid'])) {
                $error = $this->_('An existing deal cannot be moved to another pipeline.');
            } elseif ($stage === null || !hash_equals($values['pipelineUid'], $stage->pipelineUid)) {
                $error = $this->_('Selected stage is invalid.');
            } elseif (($deal === null || $deal->isOpen()) && $stage->stateType !== 'open') {
                $error = $this->_('Open deals must use an open pipeline stage.');
            } elseif ($values['valueAmount'] !== ''
                && !is_numeric(str_replace(',', '.', $values['valueAmount']))) {
                $error = $this->_('Deal value must be a number.');
            } elseif (preg_match('/^[A-Z]{3}$/', $values['currency']) !== 1) {
                $error = $this->_('Currency must be a three-letter code.');
            } elseif ($values['probability'] !== ''
                && (!ctype_digit($values['probability'])
                    || (int) $values['probability'] < 0
                    || (int) $values['probability'] > 100)) {
                $error = $this->_('Probability must be between 0 and 100.');
            }

            if ($error === '' && $values['contactUid'] !== '') {
                $contact = $this->contactRepository()->find($values['contactUid']);
                if ($contact === null || !hash_equals($this->organizationUid(), $contact->organizationId)) {
                    $error = $this->_('Selected contact is invalid.');
                }
            }
            if ($error === '' && $values['companyUid'] !== '') {
                $company = $this->companyRepository()->find($values['companyUid']);
                if ($company === null || !hash_equals($this->organizationUid(), $company->organizationId)) {
                    $error = $this->_('Selected company is invalid.');
                }
            }

            $value = null;
            $expectedCloseDate = null;
            if ($error === '' && $values['valueAmount'] !== '') {
                $value = Money::ofMinor(
                    (int) round((float) str_replace(',', '.', $values['valueAmount']) * 100),
                    $values['currency']
                );
            }
            if ($error === '' && $values['expectedCloseDate'] !== '') {
                try {
                    $expectedCloseDate = new \DateTimeImmutable($values['expectedCloseDate']);
                } catch (\Throwable) {
                    $error = $this->_('Expected close date is invalid.');
                }
            }

            if ($error === '') {
                $isNew = $deal === null;
                $deal ??= Deal::create(
                    $this->organizationUid(),
                    $values['pipelineUid'],
                    $values['stageUid'],
                    $values['title'],
                );
                $deal->pipelineUid = $values['pipelineUid'];
                $deal->stageUid = $values['stageUid'];
                $deal->title = $values['title'];
                $deal->contactUid = $values['contactUid'] ?: null;
                $deal->companyUid = $values['companyUid'] ?: null;
                $deal->source = $values['source'] ?: null;
                $deal->value = $value;
                $deal->probability = $values['probability'] !== '' ? (int) $values['probability'] : null;
                $deal->expectedCloseDate = $expectedCloseDate;
                $deal->description = $values['description'] ?: null;
                $crm->dealRepository()->save($deal);
                if ($intakeAnswers !== null) {
                    $this->saveCrmIntakeAnswers('deal', $deal->uid->toString(), $intakeAnswers);
                }
                $this->audit(
                    'crm',
                    'deal',
                    $deal->uid->toString(),
                    $isNew ? 'created' : 'updated',
                    current: ['title' => $deal->title, 'stageUid' => $deal->stageUid],
                );
                $this->message($this->_('Deal saved.'));
                $this->wire()->session->redirect(
                    '../crm-deal/?id=' . rawurlencode($deal->uid->toString())
                );
            }
            $pipelineUid = $values['pipelineUid'];
            $stages = $pipelineUid !== '' ? $crm->stageRepository()->forPipeline($pipelineUid) : [];
        }

        $this->setPageTitle($deal === null
            ? $this->_('Kontor · New deal')
            : sprintf($this->_('Kontor · %s'), $deal->title));

        $contacts = $this->contactRepository()->findAll($this->organizationUid(), limit: 250);
        $companies = $this->companyRepository()->findAll($this->organizationUid(), limit: 250);
        $selectedPipeline = $values['pipelineUid'] !== ''
            ? $crm->pipelineRepository()->find($values['pipelineUid'])
            : null;
        if ($selectedPipeline !== null
            && !hash_equals($this->organizationUid(), $selectedPipeline->organizationId)) {
            $selectedPipeline = null;
        }
        $selectedStage = $values['stageUid'] !== ''
            ? $crm->stageRepository()->find($values['stageUid'])
            : null;
        if ($selectedStage !== null
            && ($selectedPipeline === null
                || !hash_equals($selectedPipeline->uid->toString(), $selectedStage->pipelineUid))) {
            $selectedStage = null;
        }
        $selectedContact = $values['contactUid'] !== ''
            ? $this->contactRepository()->find($values['contactUid'])
            : null;
        if ($selectedContact !== null
            && !hash_equals($this->organizationUid(), $selectedContact->organizationId)) {
            $selectedContact = null;
        }
        $selectedCompany = $values['companyUid'] !== ''
            ? $this->companyRepository()->find($values['companyUid'])
            : null;
        if ($selectedCompany !== null
            && !hash_equals($this->organizationUid(), $selectedCompany->organizationId)) {
            $selectedCompany = null;
        }
        $dealQuotations = $deal !== null
            && $this->salesReady()
            && $this->can('kontor-sales-quotation-view')
            ? $this->salesModule()->quotationRepository()->forDeal(
                $this->organizationUid(),
                $deal->uid->toString(),
            )
            : [];

        return $this->renderTemplate('crm-deal', [
            'deal' => $deal,
            'values' => $values,
            'pipelines' => $pipelines,
            'stages' => $stages,
            'contacts' => $contacts,
            'companies' => $companies,
            'selectedPipeline' => $selectedPipeline,
            'selectedStage' => $selectedStage,
            'selectedContact' => $selectedContact,
            'selectedCompany' => $selectedCompany,
            'canEdit' => $canEdit,
            'canMove' => $deal !== null && $deal->isOpen() && $this->can('kontor-crm-deal-move'),
            'canCloseWon' => $deal !== null && $deal->isOpen()
                && $this->can('kontor-crm-deal-close-won'),
            'canCloseLost' => $deal !== null && $deal->isOpen()
                && $this->can('kontor-crm-deal-close-lost'),
            'canViewContact' => $this->can('kontor-contacts-contact-view'),
            'canViewCompany' => $this->can('kontor-contacts-company-view'),
            'dealQuotations' => $dealQuotations,
            'canCreateQuotation' => $deal !== null
                && $deal->status === 'won'
                && ($deal->companyUid !== null || $deal->contactUid !== null)
                && $dealQuotations === []
                && $this->salesReady()
                && $this->can('kontor-sales-quotation-create'),
            'error' => $error,
            'intakeFields' => $intakeFields,
            'intakeValues' => $intakeValues,
        ]);
    }

    public function ___executeCrmDealAction(): void
    {
        $this->requirePost();
        $this->requireCrm();
        $action = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('action'),
            ['move', 'won', 'lost', 'archive', 'restore']
        );
        $this->requireAction($action, ['move', 'won', 'lost', 'archive', 'restore']);
        $permissions = [
            'move' => 'kontor-crm-deal-move',
            'won' => 'kontor-crm-deal-close-won',
            'lost' => 'kontor-crm-deal-close-lost',
            'archive' => 'kontor-crm-deal-edit',
            'restore' => 'kontor-crm-deal-edit',
        ];
        $this->requirePermission($permissions[$action]);
        /** @var KontorCRM $crm */
        $crm = $this->wire()->modules->get('KontorCRM');
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->post('id'));
        $deal = $crm->dealRepository()->require($id);
        $this->requireSameOrganization($deal->organizationId);

        if ($action === 'move') {
            $stageUid = $this->wire()->sanitizer->text((string) $this->wire()->input->post('stage_uid'));
            $crm->crmService()->moveDealToStage($id, $stageUid);
        } elseif ($action === 'won') {
            $crm->crmService()->closeDealWon($id);
        } elseif ($action === 'lost') {
            $crm->crmService()->closeDealLost(
                $id,
                $this->wire()->sanitizer->text((string) $this->wire()->input->post('lost_reason')) ?: null,
            );
        } elseif ($action === 'restore') {
            $crm->dealRepository()->restore($id);
        } else {
            $crm->dealRepository()->archive($id);
        }

        $this->audit('crm', 'deal', $id, $action);
        $this->message($this->_('Deal updated.'));
        $returnTo = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('return_to'),
            ['deal'],
        );
        $this->wire()->session->redirect($returnTo === 'deal'
            ? '../crm-deal/?id=' . rawurlencode($deal->uid->toString())
            : '../crm-deals/?pipeline=' . rawurlencode($deal->pipelineUid));
    }
}

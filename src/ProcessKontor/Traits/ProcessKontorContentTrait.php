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
use Kontor\Documents\Application\DocumentFileName;
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
trait ProcessKontorContentTrait
{

    public function ___executeCache(): string
    {
        $this->requireCache();
        $this->requirePermission('kontor-cache-view');
        $state = $this->wire()->session->get('kontorCacheWorkbench');
        $state = is_array($state) ? $state : [];
        $this->wire()->session->set('kontorCacheWorkbench', null);
        $this->setPageTitle($this->_('Kontor · Cache'));

        return $this->renderTemplate('cache', [
            'health' => $this->cacheModule()->healthCheck()->run(),
            'state' => array_replace([
                'namespace' => 'operations',
                'key' => 'example',
                'tags' => 'demo',
                'ttl' => '300',
                'valueJson' => '{"status":"ready"}',
                'result' => null,
            ], $state),
            'effectiveNamespacePrefix' => 'kontor-admin-org-' . $this->organizationInternalId() . '-',
            'consumers' => [
                [
                    'name' => 'Search',
                    'status' => $this->searchReady() ? 'connected' : 'unavailable',
                    'namespace' => 'search',
                    'policy' => '30-second query cache · results tag invalidated after indexing',
                ],
            ],
            'canManage' => $this->can('kontor-cache-manage'),
            'canFlush' => $this->can('kontor-cache-flush'),
        ]);
    }

    public function ___executeCacheOperate(): void
    {
        $this->requirePost();
        $this->requireCache();
        $this->requirePermission('kontor-cache-manage');
        $action = strtolower($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('action')
        ));
        $this->requireAction($action, ['set', 'get', 'delete']);
        $input = $this->cacheWorkbenchInput();
        $cache = $this->cacheModule()->manager()->forNamespace($input['effectiveNamespace']);
        $result = null;

        if ($action === 'set') {
            $value = $this->cacheValueFromPost($input['valueJson']);
            $cache->set($input['key'], $value, $input['ttl'], $input['tags']);
            $result = [
                'action' => 'set',
                'hit' => true,
                'value' => $value,
                'message' => $this->_('Value stored in the selected namespace and tag generation.'),
            ];
        } elseif ($action === 'get') {
            $hit = $cache->has($input['key'], $input['tags']);
            $result = [
                'action' => 'get',
                'hit' => $hit,
                'value' => $hit ? $cache->get($input['key'], $input['tags']) : null,
                'message' => $hit ? $this->_('Cache hit.') : $this->_('Cache miss.'),
            ];
        } else {
            $wasPresent = $cache->has($input['key'], $input['tags']);
            $cache->delete($input['key'], $input['tags']);
            $result = [
                'action' => 'delete',
                'hit' => $wasPresent,
                'value' => null,
                'message' => $wasPresent
                    ? $this->_('Entry deleted from the current generation.')
                    : $this->_('Entry was already missing.'),
            ];
        }

        $this->wire()->session->set('kontorCacheWorkbench', [
            'namespace' => $input['namespace'],
            'key' => $input['key'],
            'tags' => implode(', ', $input['tags']),
            'ttl' => (string) ($input['ttl'] ?? 0),
            'valueJson' => $input['valueJson'],
            'result' => $result,
        ]);
        $this->audit('cache', 'namespace', $this->organizationUid(), $action, metadata: [
            'namespace' => $input['namespace'],
            'key' => $input['key'],
            'tags' => $input['tags'],
            'ttl' => $input['ttl'],
            'hit' => $result['hit'],
        ]);
        $this->message($result['message']);
        $this->wire()->session->redirect('../cache/');
    }

    public function ___executeCacheFlush(): void
    {
        $this->requirePost();
        $this->requireCache();
        $this->requirePermission('kontor-cache-flush');
        $action = strtolower($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('action')
        ));
        $this->requireAction($action, ['flush-tag', 'flush-namespace']);
        $input = $this->cacheWorkbenchInput();
        $cache = $this->cacheModule()->manager()->forNamespace($input['effectiveNamespace']);
        if ($action === 'flush-tag') {
            if (count($input['tags']) !== 1) {
                throw new WireException($this->_('Tag invalidation requires exactly one tag.'));
            }
            $cache->flushTag($input['tags'][0]);
            $message = $this->_('Tag generation invalidated; matching entries now miss.');
        } else {
            $cache->flushNamespace();
            $message = $this->_('Namespace generation invalidated; all its entries now miss.');
        }
        $this->wire()->session->set('kontorCacheWorkbench', [
            'namespace' => $input['namespace'],
            'key' => $input['key'],
            'tags' => implode(', ', $input['tags']),
            'ttl' => (string) ($input['ttl'] ?? 0),
            'valueJson' => $input['valueJson'],
            'result' => [
                'action' => $action,
                'hit' => false,
                'value' => null,
                'message' => $message,
            ],
        ]);
        $this->audit('cache', 'namespace', $this->organizationUid(), $action, metadata: [
            'namespace' => $input['namespace'],
            'tag' => $action === 'flush-tag' ? $input['tags'][0] : null,
        ]);
        $this->message($message);
        $this->wire()->session->redirect('../cache/');
    }

    public function ___executeDocuments(): string
    {
        $this->requireDocuments();
        $this->requirePermission('kontor-documents-template-view');
        $module = $this->documentsModule();
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->get('id'));
        $selected = $id !== '' ? $module->templateRepository()->require($id) : null;
        if ($selected !== null) {
            $this->requireSameOrganization($selected->organizationId);
        }
        $preview = $this->wire()->session->get('kontorDocumentsPreview');
        $this->wire()->session->set('kontorDocumentsPreview', null);
        $this->setPageTitle($selected !== null
            ? sprintf($this->_('Kontor · %s'), $selected->name)
            : $this->_('Kontor · Documents'));

        $filesReady = $this->wire()->modules->isInstalled('KontorFiles');
        $dynamicPreview = $selected !== null && in_array($selected->documentType, ['letter', 'contract'], true);
        $contactsReady = $this->contactsReady() && $this->can('kontor-contacts-company-view');

        return $this->renderTemplate('documents', [
            'templates' => $module->templateRepository()->forOrganization($this->organizationUid()),
            'selected' => $selected,
            'preview' => is_array($preview) ? $preview : null,
            'canCreate' => $this->can('kontor-documents-template-create'),
            'canEdit' => $this->can('kontor-documents-template-edit'),
            'canArchive' => $this->can('kontor-documents-template-archive'),
            'canRender' => $filesReady && $this->can('kontor-documents-render'),
            'filesReady' => $filesReady,
            'templateFields' => $dynamicPreview ? $module->renderService()->fields($selected) : [],
            'companies' => $contactsReady
                ? $this->companyRepository()->findAll($this->organizationUid(), limit: 250)
                : [],
            'contactsReady' => $contactsReady,
        ]);
    }

    public function ___executeDocumentsPublish(): void
    {
        $this->requirePost();
        $this->requireDocuments();
        $templateKey = strtolower(trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('template_key')
        )));
        $documentType = strtolower(trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('document_type')
        )));
        $language = strtolower(trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('language')
        )));
        $name = trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('name')
        ));
        $body = trim((string) $this->wire()->input->post('body_html'));
        $css = trim((string) $this->wire()->input->post('custom_css'));
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $templateKey)
            || !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $documentType)
            || !preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language)
            || $name === ''
            || $body === ''
            || strlen($body) > 262144
            || strlen($css) > 65536) {
            throw new WireException($this->_('Valid template metadata and HTML body are required.'));
        }
        $existing = $this->documentsModule()->templateRepository()->findCurrentVersionExact(
            $this->organizationUid(),
            $templateKey,
            $language,
        );
        $this->requirePermission($existing === null
            ? 'kontor-documents-template-create'
            : 'kontor-documents-template-edit');
        $template = $this->documentsModule()->templateManager()->publish(
            $this->organizationUid(),
            $templateKey,
            $documentType,
            $language,
            mb_substr($name, 0, 255),
            $body,
            $css !== '' ? $css : null,
            (int) $this->wire()->user->id,
        );
        $this->audit('documents', 'template', $template->uid->toString(), 'published', metadata: [
            'key' => $templateKey,
            'language' => $language,
            'version' => $template->versionNumber,
        ]);
        $this->message($this->_('Document template version published.'));
        $this->wire()->session->redirect(
            '../documents/?id=' . rawurlencode($template->uid->toString())
        );
    }

    public function ___executeDocumentsPreview(): void
    {
        $this->requirePost();
        $this->requireDocuments();
        $this->requireFiles();
        $this->requirePermission('kontor-documents-render');
        $uid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('template_uid')
        );
        $template = $this->documentsModule()->templateRepository()->require($uid);
        $this->requireSameOrganization($template->organizationId);
        $rawData = trim((string) $this->wire()->input->post('data_json'));
        if (strlen($rawData) > 262144) {
            throw new WireException($this->_('Advanced preview data must be at most 256 KB.'));
        }
        $renderer = $this->documentsModule()->renderService();
        if ($rawData !== '') {
            try {
                $data = json_decode($rawData, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new WireException($this->_('Advanced preview data must contain valid JSON.'));
            }
            if (!is_array($data)) {
                throw new WireException($this->_('Advanced preview data must be a JSON object or array.'));
            }
        } elseif (in_array($template->documentType, ['letter', 'contract'], true)) {
            $allowedFields = $renderer->fields($template);
            $fieldKeys = (array) $this->wire()->input->post('field_key');
            $fieldValues = (array) $this->wire()->input->post('field_value');
            if (count($fieldKeys) > 100 || count($fieldKeys) !== count($fieldValues)) {
                throw new WireException($this->_('The document fields are invalid.'));
            }
            $data = [];
            $fieldBytes = 0;
            foreach ($fieldKeys as $index => $fieldKey) {
                $fieldKey = (string) $fieldKey;
                if (!in_array($fieldKey, $allowedFields, true)) {
                    throw new WireException($this->_('The document contains an unexpected field.'));
                }
                $value = trim($this->wire()->sanitizer->textarea((string) ($fieldValues[$index] ?? '')));
                if (strlen($value) > 10000) {
                    throw new WireException($this->_('A document field exceeds the 10 KB limit.'));
                }
                $fieldBytes += strlen($value);
                if ($fieldBytes > 262144) {
                    throw new WireException($this->_('Document fields must be at most 256 KB in total.'));
                }
                $this->setDocumentDataValue($data, $fieldKey, $value);
            }
        } else {
            $title = trim($this->wire()->sanitizer->text(
                (string) $this->wire()->input->post('preview_title')
            ));
            $customer = trim($this->wire()->sanitizer->text(
                (string) $this->wire()->input->post('customer_name')
            ));
            $description = trim($this->wire()->sanitizer->text(
                (string) $this->wire()->input->post('line_description')
            ));
            $amount = trim($this->wire()->sanitizer->text(
                (string) $this->wire()->input->post('line_amount')
            ));
            $note = trim($this->wire()->sanitizer->textarea(
                (string) $this->wire()->input->post('note')
            ));
            if ($title === '' || $customer === '' || $description === '' || $amount === '') {
                throw new WireException($this->_('Title, customer, line description and amount are required for a preview.'));
            }
            $data = [
                'title' => mb_substr($title, 0, 255),
                'customer' => ['name' => mb_substr($customer, 0, 255)],
                'lines' => [[
                    'description' => mb_substr($description, 0, 500),
                    'amount' => mb_substr($amount, 0, 100),
                ]],
                'note' => $note !== '' ? mb_substr($note, 0, 1000) : null,
            ];
        }
        $companyUid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('company_uid')
        );
        $company = null;
        if ($companyUid !== '') {
            if (!in_array($template->documentType, ['letter', 'contract'], true)) {
                throw new WireException($this->_('Only letters and contracts can be linked directly to a company.'));
            }
            if (!$this->contactsReady()) {
                throw new WireException($this->_('Kontor Contacts is required to link a company.'));
            }
            $this->requirePermission('kontor-contacts-company-view');
            $company = $this->companyRepository()->require($companyUid);
            $this->requireSameOrganization($company->organizationId);
            if ($this->documentDataValue($data, 'client_name') === null
                || trim((string) $this->documentDataValue($data, 'client_name')) === '') {
                $this->setDocumentDataValue($data, 'client_name', $company->tradingName ?: $company->legalName);
            }
        }
        $snapshot = $this->documentsModule()->snapshotBuilder()->build($template, $data);
        $pdf = $renderer->renderPdf($template, $data);
        $filenameStem = preg_replace('/[^A-Za-z0-9._-]/', '-', $template->templateKey) ?: 'document';
        if ($company !== null) {
            $originalName = DocumentFileName::forCompany(
                $template->templateKey,
                $company->tradingName ?: $company->legalName,
                new \DateTimeImmutable(),
            );
        } else {
            $originalName = $filenameStem . '-v' . $template->versionNumber . '.pdf';
        }
        $stored = $this->filesModule()->fileManager()->upload(
            organizationUid: $this->organizationUid(),
            originalName: $originalName,
            contents: $pdf,
            visibility: 'private',
            classification: 'confidential',
            entityType: $company !== null ? 'company' : 'document_template',
            entityUid: $company?->uid->toString() ?? $template->uid->toString(),
            metadata: [
                'source' => 'documents',
                'documentType' => $template->documentType,
                'templateKey' => $template->templateKey,
                'documentSnapshot' => $snapshot,
                'companyUid' => $company?->uid->toString(),
            ],
            actorId: (int) $this->wire()->user->id,
        );
        $this->wire()->session->set('kontorDocumentsPreview', [
            'templateUid' => $uid,
            'html' => $renderer->renderPreviewHtml($template, $data),
            'snapshot' => $snapshot,
            'pdfBytes' => strlen($pdf),
            'fileUid' => $stored['uid'],
            'fileVersion' => $stored['versionNumber'],
            'dataJson' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $this->audit('documents', 'template', $uid, 'rendered', metadata: [
            'pdfBytes' => strlen($pdf),
            'snapshotVersion' => $snapshot['templateVersion'],
            'fileUid' => $stored['uid'],
            'fileVersion' => $stored['versionNumber'],
        ]);
        $this->audit('files', 'file', $stored['uid'], 'generated', metadata: [
            'sourceComponent' => 'documents',
            'templateUid' => $uid,
            'version' => $stored['versionNumber'],
        ]);
        $this->message($this->_('HTML, PDF, and immutable snapshot rendered; the PDF was stored privately.'));
        $this->wire()->session->redirect('../documents/?id=' . rawurlencode($uid));
    }

    /** @param array<string, mixed> $data */
    private function setDocumentDataValue(array &$data, string $path, string $value): void
    {
        $segments = explode('.', $path);
        $target =& $data;
        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target =& $target[$segment];
        }
        $target = $value;
    }

    /** @param array<string, mixed> $data */
    private function documentDataValue(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function ___executeDocumentsArchive(): void
    {
        $this->requirePost();
        $this->requireDocuments();
        $this->requirePermission('kontor-documents-template-archive');
        $uid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('template_uid')
        );
        $action = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('action')
        );
        $this->requireAction($action, ['archive', 'restore']);
        $template = $this->documentsModule()->templateRepository()->require($uid);
        $this->requireSameOrganization($template->organizationId);
        $repository = $this->documentsModule()->templateRepository();
        $action === 'archive' ? $repository->archive($uid) : $repository->restore($uid);
        $this->audit('documents', 'template', $uid, $action . 'd');
        $this->message($action === 'archive'
            ? $this->_('Template version archived.')
            : $this->_('Template version restored.'));
        $this->wire()->session->redirect('../documents/?id=' . rawurlencode($uid));
    }

    public function ___executeAI(): string
    {
        $this->requireAI();
        $this->requirePermission('kontor-ai-action-approve');
        $module = $this->aiModule();
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->get('id'));
        $selected = $id !== '' ? $module->pendingActionRepository()->require($id) : null;
        if ($selected !== null) {
            $this->requireSameOrganization($selected->organizationId);
        }
        $actions = $module->pendingActionRepository()->forOrganization($this->organizationUid());
        $actionRequesters = [];
        foreach ($actions as $action) {
            $requester = $action->requestedBy !== null
                ? $this->wire()->users->get((int) $action->requestedBy)
                : null;
            $actionRequesters[$action->uid->toString()] = $requester?->id
                ? (string) ($requester->get('title') ?: $requester->name)
                : $this->_('Automation');
        }
        $result = $this->wire()->session->get('kontorAIWorkbenchResult');
        $this->wire()->session->set('kontorAIWorkbenchResult', null);
        $this->setPageTitle($this->_('Kontor · AI'));

        return $this->renderTemplate('ai', [
            'providers' => $module->providerRegistry()->all(),
            'actions' => $actions,
            'actionRequesters' => $actionRequesters,
            'selected' => $selected,
            'result' => is_array($result) ? $result : null,
        ]);
    }

    public function ___executeAIExecute(): void
    {
        $this->requirePost();
        $this->requireAI();
        $this->requirePermission('kontor-ai-action-approve');
        $capability = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('capability')
        );
        $this->requireAction($capability, ['summarize', 'draft', 'extract']);
        $text = trim((string) $this->wire()->input->post('text'));
        $instructions = trim((string) $this->wire()->input->post('instructions'));
        if ($text === '' || strlen($text) > 262144) {
            throw new WireException($this->_('Input text is required and must be at most 256 KB.'));
        }
        $simulate = (bool) $this->wire()->input->post('simulate');
        $module = $this->aiModule();
        $gateway = $simulate ? $module->previewGateway() : $module->gateway();
        $result = null;
        $pendingUid = '';

        if ($capability === 'summarize') {
            $response = (new \Kontor\AI\Application\SummaryService($gateway))->summarize(
                $this->organizationUid(),
                $text,
                (string) $this->wire()->user->id,
            );
            $result = [
                'capability' => $capability,
                'success' => $response->success,
                'output' => $response->output,
                'error' => $response->errorMessage,
                'pending' => false,
                'simulated' => $simulate,
            ];
        } elseif ($capability === 'extract') {
            $schema = $this->aiExtractionSchemaFromPost();
            $normalizedSchema = [];
            foreach ($schema as $field => $type) {
                if (!is_scalar($type)) {
                    throw new WireException($this->_('Extraction schema values must be scalar type names.'));
                }
                $normalizedSchema[(string) $field] = (string) $type;
            }
            $response = (new \Kontor\AI\Application\ExtractionService($gateway))->extract(
                $this->organizationUid(),
                $text,
                $normalizedSchema,
                (string) $this->wire()->user->id,
            );
            $result = [
                'capability' => $capability,
                'success' => $response->success,
                'output' => $response->output,
                'error' => $response->errorMessage,
                'pending' => false,
                'simulated' => $simulate,
            ];
        } else {
            if ($instructions === '') {
                throw new WireException($this->_('Draft instructions are required.'));
            }
            $context = $this->aiJsonObjectFromPost('context_json');
            $approvalService = $simulate ? $module->previewApprovals() : $module->approvals();
            $approvalResult = $approvalService->requestAndMaybeApprove(new AIRequest(
                capability: 'draft',
                organizationId: $this->organizationUid(),
                input: ['context' => array_merge($context, ['sourceText' => $text]), 'instructions' => $instructions],
                requiresConfirmation: true,
                actorId: (string) $this->wire()->user->id,
            ), requestedBy: (int) $this->wire()->user->id);
            if ($approvalResult->isPending && $approvalResult->pendingAction !== null) {
                $pendingUid = $approvalResult->pendingAction->uid->toString();
                $result = [
                    'capability' => $capability,
                    'success' => true,
                    'output' => [],
                    'error' => null,
                    'pending' => true,
                    'pendingUid' => $pendingUid,
                    'simulated' => $simulate,
                ];
                $this->audit('ai', 'pending_action', $pendingUid, 'requested', metadata: [
                    'simulated' => $simulate,
                ]);
            } else {
                $response = $approvalResult->response;
                $result = [
                    'capability' => $capability,
                    'success' => $response?->success ?? false,
                    'output' => $response?->output ?? [],
                    'error' => $response?->errorMessage,
                    'pending' => false,
                    'simulated' => $simulate,
                ];
            }
        }

        $this->wire()->session->set('kontorAIWorkbenchResult', $result);
        $this->message($result['pending']
            ? $this->_('AI draft withheld for human approval.')
            : $this->_('AI workbench request completed.'));
        $redirect = '../ai/';
        if ($pendingUid !== '') {
            $redirect .= '?id=' . rawurlencode($pendingUid);
        }
        $this->wire()->session->redirect($redirect);
    }

    public function ___executeAIDecide(): void
    {
        $this->requirePost();
        $this->requireAI();
        $this->requirePermission('kontor-ai-action-approve');
        $uid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('action_uid')
        );
        $decision = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('decision')
        );
        $this->requireAction($decision, ['approve', 'reject']);
        $pending = $this->aiModule()->pendingActionRepository()->require($uid);
        $this->requireSameOrganization($pending->organizationId);
        if (!$pending->isPending()) {
            throw new WireException($this->_('This AI action has already been decided.'));
        }
        $external = $this->aiModule()->externalApprovals()->referenceForPending($uid);
        if($external && $external['provider'] === 'mailbox') {
            $this->requirePermission('mailbox-confirm-links');
            if(!$this->wire()->modules->isInstalled('Mailbox')) throw new WireException($this->_('Mailbox is not installed.'));
            $mailboxApi = $this->wire()->modules->get('Mailbox')->api($this->wire()->user);
            try {
                if($decision === 'approve') $mailboxApi->approveConfirmation($external['external_id']);
                else $mailboxApi->rejectConfirmation($external['external_id']);
            } catch(\Throwable $error) {
                $wanted = $decision === 'approve' ? 'approved' : 'rejected';
                $applied = false;
                try {
                    foreach($mailboxApi->proposals() as $proposal) {
                        if(($proposal['id'] ?? '') === $external['external_id'] && ($proposal['status'] ?? '') === $wanted) $applied = true;
                    }
                } catch(\Throwable $ignored) {}
                if(!$applied) throw new WireException($this->_('Mailbox decision failed; the Kontor action was not changed.'));
            }
        }
        $decided = $decision === 'approve'
            ? $this->aiModule()->approvals()->approve($uid, (int) $this->wire()->user->id)
            : $this->aiModule()->approvals()->reject($uid, (int) $this->wire()->user->id);
        $this->audit('ai', 'pending_action', $uid, $decided->status);
        $this->message($decision === 'approve'
            ? $this->_('AI action approved.')
            : $this->_('AI action rejected.'));
        $this->wire()->session->redirect('../ai/?id=' . rawurlencode($uid));
    }

    public function ___executeLedger(): string
    {
        $this->requireLedger();
        $this->requirePermission('kontor-ledger-entry-view');
        $module = $this->ledgerModule();
        $accounts = $module->accountRepository()->forOrganization($this->organizationUid());
        $accountRows = [];
        foreach ($accounts as $account) {
            $accountRows[] = [
                'account' => $account,
                'balance' => $module->balances()->balance($account->uid->toString()),
            ];
        }
        $entries = $module->entryRepository()->forOrganization($this->organizationUid());
        $entryRows = [];
        $referenceLinks = [];
        $recordedByLabels = [];
        foreach ($entries as $entry) {
            $lines = $module->lineRepository()->forEntry($entry->uid->toString());
            $debitMinor = array_sum(array_map(
                static fn ($line): int => $line->debit->amountMinor(),
                $lines,
            ));
            $currencyCode = $lines !== []
                ? $lines[0]->debit->currencyCode()
                : $this->organization()->defaultCurrency;
            $entryRows[] = [
                'entry' => $entry,
                'amount' => Money::ofMinor($debitMinor, $currencyCode),
            ];
            $entryUid = $entry->uid->toString();
            $recordedByLabels[$entryUid] = $this->_('System');
            if ($entry->createdBy !== null) {
                $recordedBy = $this->wire()->users->get($entry->createdBy);
                $recordedByLabels[$entryUid] = $recordedBy->id
                    ? ((string) $recordedBy->get('title') ?: (string) $recordedBy->name)
                    : $this->_('Team member');
            }
            if (
                in_array($entry->referenceType, [
                    LedgerInvoicePostingService::ISSUE_REFERENCE,
                    LedgerInvoicePostingService::CANCELLATION_REFERENCE,
                ], true)
                && $entry->referenceUid !== null
                && $this->invoicesReady()
                && $this->can('kontor-invoices-invoice-view')
            ) {
                $invoice = $this->invoiceModule()->invoiceRepository()->find($entry->referenceUid);
                if ($invoice !== null && hash_equals($invoice->organizationId, $this->organizationUid())) {
                    $referenceLinks[$entryUid] = [
                        'label' => $this->_('Open invoice'),
                        'url' => 'invoice/?id=' . rawurlencode($entry->referenceUid),
                    ];
                }
            } elseif (
                in_array($entry->referenceType, [
                    LedgerAllocationPostingService::POSTING_REFERENCE,
                    LedgerAllocationPostingService::REVERSAL_REFERENCE,
                ], true)
                && $entry->referenceUid !== null
                && $this->paymentsReady()
                && $this->can('kontor-payments-payment-view')
            ) {
                $allocation = $this->paymentModule()->allocationRepository()->find($entry->referenceUid);
                if ($allocation !== null && hash_equals($allocation->organizationId, $this->organizationUid())) {
                    $referenceLinks[$entryUid] = [
                        'label' => $this->_('Open payment'),
                        'url' => 'payment/?id=' . rawurlencode($allocation->paymentUid),
                    ];
                }
            } elseif (
                $entry->referenceType === LedgerExpensePostingService::REFERENCE_TYPE
                && $entry->referenceUid !== null
                && $this->expensesReady()
                && $this->can('kontor-expenses-expense-view')
            ) {
                $expense = $this->expensesModule()->expenseRepository()->find($entry->referenceUid);
                if ($expense !== null && hash_equals($expense->organizationId, $this->organizationUid())) {
                    $referenceLinks[$entryUid] = [
                        'label' => $this->_('Open expense'),
                        'url' => 'expense/?id=' . rawurlencode($entry->referenceUid),
                    ];
                }
            }
        }
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->get('id'));
        $selected = $id !== '' ? $module->entryRepository()->require($id) : null;
        if ($selected !== null) {
            $this->requireSameOrganization($selected->organizationId);
        }
        $accountLabels = [];
        foreach ($accounts as $account) {
            $accountLabels[$account->uid->toString()] = $account->code . ' · ' . $account->name;
        }
        $financeLinks = [];
        if ($this->invoicesReady() && $this->can('kontor-invoices-invoice-view')) {
            $financeLinks[] = ['label' => $this->_('Invoices'), 'url' => 'invoices/', 'icon' => 'file-text-o'];
        }
        if ($this->paymentsReady() && $this->can('kontor-payments-payment-view')) {
            $financeLinks[] = ['label' => $this->_('Payments'), 'url' => 'payments/', 'icon' => 'credit-card'];
        }
        if ($this->expensesReady() && $this->can('kontor-expenses-expense-view')) {
            $financeLinks[] = ['label' => $this->_('Expenses'), 'url' => 'expenses/', 'icon' => 'money'];
        }
        $this->setPageTitle($selected !== null
            ? $this->_('Kontor · Journal entry')
            : $this->_('Kontor · Ledger'));

        return $this->renderTemplate('ledger', [
            'accountRows' => $accountRows,
            'activeAccounts' => array_values(array_filter(
                $accounts,
                static fn (Account $account): bool => $account->isActive(),
            )),
            'entryRows' => $entryRows,
            'referenceLinks' => $referenceLinks,
            'recordedByLabels' => $recordedByLabels,
            'financeLinks' => $financeLinks,
            'selected' => $selected,
            'selectedLines' => $selected !== null
                ? $module->lineRepository()->forEntry($selected->uid->toString())
                : [],
            'accountLabels' => $accountLabels,
            'defaultCurrency' => $this->organization()->defaultCurrency,
            'canManageAccounts' => $this->can('kontor-ledger-account-manage'),
            'canRecordEntries' => $this->can('kontor-ledger-entry-record'),
        ]);
    }

    public function ___executeLedgerAccountCreate(): void
    {
        $this->requirePost();
        $this->requireLedger();
        $this->requirePermission('kontor-ledger-account-manage');
        $code = strtoupper(trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('code')
        )));
        $name = trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('name')
        ));
        $type = $this->wire()->sanitizer->text((string) $this->wire()->input->post('type'));
        $currency = strtoupper(trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('currency_code')
        )));
        $parentUid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('parent_uid')
        );
        if (preg_match('/^[A-Z0-9._-]{1,20}$/', $code) !== 1) {
            throw new WireException($this->_('Account code may contain letters, numbers, dots, underscores, and hyphens.'));
        }
        if ($name === '') {
            throw new WireException($this->_('Account name is required.'));
        }
        $this->requireAction($type, Account::TYPES);
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new WireException($this->_('Currency must be a three-letter code.'));
        }
        if ($parentUid !== '') {
            $parent = $this->ledgerModule()->accountRepository()->require($parentUid);
            $this->requireSameOrganization($parent->organizationId);
            if (!$parent->isActive()) {
                throw new WireException($this->_('Parent account must be active.'));
            }
        }
        $account = $this->ledgerModule()->chartOfAccounts()->createAccount(
            $this->organizationUid(),
            $code,
            mb_substr($name, 0, 255),
            $type,
            $currency,
            $parentUid ?: null,
            (int) $this->wire()->user->id,
        );
        $this->audit('ledger', 'account', $account->uid->toString(), 'created', current: [
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
        ]);
        $this->message($this->_('Ledger account created.'));
        $this->wire()->session->redirect('../ledger/');
    }

    public function ___executeLedgerAccountAction(): void
    {
        $this->requirePost();
        $this->requireLedger();
        $this->requirePermission('kontor-ledger-account-manage');
        $uid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('account_uid')
        );
        $action = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('action')
        );
        $this->requireAction($action, ['archive', 'restore']);
        $account = $this->ledgerModule()->accountRepository()->require($uid);
        $this->requireSameOrganization($account->organizationId);
        if ($action === 'archive') {
            $this->ledgerModule()->chartOfAccounts()->archive($uid);
        } else {
            $this->ledgerModule()->chartOfAccounts()->restore($uid);
        }
        $this->audit('ledger', 'account', $uid, $action . 'd');
        $this->message($action === 'archive'
            ? $this->_('Ledger account archived.')
            : $this->_('Ledger account restored.'));
        $this->wire()->session->redirect('../ledger/');
    }

    public function ___executeLedgerEntryRecord(): void
    {
        $this->requirePost();
        $this->requireLedger();
        $this->requirePermission('kontor-ledger-entry-record');
        $description = trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('description')
        ));
        if ($description === '') {
            throw new WireException($this->_('Entry description is required.'));
        }
        $dateText = trim((string) $this->wire()->input->post('entry_date'));
        $entryDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
        if ($entryDate === false || $entryDate->format('Y-m-d') !== $dateText) {
            throw new WireException($this->_('Entry date must use YYYY-MM-DD.'));
        }
        $debitUid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('debit_account_uid')
        );
        $creditUid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('credit_account_uid')
        );
        if ($debitUid === $creditUid) {
            throw new WireException($this->_('Debit and credit accounts must differ.'));
        }
        $accounts = $this->ledgerModule()->accountRepository();
        $debitAccount = $accounts->require($debitUid);
        $creditAccount = $accounts->require($creditUid);
        $this->requireSameOrganization($debitAccount->organizationId);
        $this->requireSameOrganization($creditAccount->organizationId);
        if (!$debitAccount->isActive() || !$creditAccount->isActive()) {
            throw new WireException($this->_('Both ledger accounts must be active.'));
        }
        if ($debitAccount->currencyCode !== $creditAccount->currencyCode) {
            throw new WireException($this->_('Debit and credit accounts must use the same currency.'));
        }
        $amount = Money::ofMinor(
            $this->ledgerAmountMinorFromPost(),
            $debitAccount->currencyCode,
        );
        $referenceType = trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('reference_type')
        ));
        $referenceUid = strtoupper(trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('reference_uid')
        )));
        if (($referenceType === '') !== ($referenceUid === '')) {
            throw new WireException($this->_('Reference type and UID must be provided together.'));
        }
        if ($referenceUid !== '' && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $referenceUid) !== 1) {
            throw new WireException($this->_('Reference UID must be a valid ULID.'));
        }
        $entry = $this->ledgerModule()->entries()->record(
            $this->organizationUid(),
            mb_substr($description, 0, 500),
            $entryDate,
            [
                LedgerLineInput::debit($debitUid, $amount),
                LedgerLineInput::credit($creditUid, $amount),
            ],
            $referenceType ?: null,
            $referenceUid ?: null,
            (int) $this->wire()->user->id,
        );
        $this->audit('ledger', 'entry', $entry->uid->toString(), 'recorded', current: [
            'description' => $entry->description,
            'amountMinor' => $amount->amountMinor(),
            'currencyCode' => $amount->currencyCode(),
        ]);
        $this->message($this->_('Balanced ledger entry recorded.'));
        $this->wire()->session->redirect(
            '../ledger/?id=' . rawurlencode($entry->uid->toString())
        );
    }

    public function ___executeGermany(): string
    {
        $this->requireGermany();
        $this->requirePermission('kontor-germany-view');
        $module = $this->germanyModule();
        $definitions = $module->chartOfAccountsSeeder()->definitions();
        $chartStatus = [];
        foreach ($definitions as $definition) {
            $account = $this->ledgerModule()->accountRepository()->findByCode(
                $this->organizationUid(),
                $definition['code'],
            );
            $chartStatus[] = [
                'definition' => $definition,
                'account' => $account,
                'compatible' => $account === null || (
                    $account->name === $definition['name']
                    && $account->type === $definition['type']
                    && $account->currencyCode === 'EUR'
                ),
            ];
        }
        $taxResult = $this->wire()->session->get('kontorGermanyTaxResult');
        $xmlResult = $this->wire()->session->get('kontorGermanyXmlResult');
        $this->wire()->session->set('kontorGermanyTaxResult', null);
        $this->wire()->session->set('kontorGermanyXmlResult', null);
        $provider = $module->localizationProvider();
        $this->setPageTitle($this->_('Kontor · Germany'));

        return $this->renderTemplate('germany', [
            'countryCode' => $provider->countryCode(),
            'documentFormats' => $provider->supportedDocumentFormats(),
            'chartStatus' => $chartStatus,
            'taxResult' => is_array($taxResult) ? $taxResult : null,
            'xmlResult' => is_array($xmlResult) ? $xmlResult : null,
            'canConfigure' => $this->can('kontor-germany-configure'),
            'canViewLedger' => $this->can('kontor-ledger-entry-view'),
        ]);
    }

    public function ___executeGermanyTaxId(): void
    {
        $this->requirePost();
        $this->requireGermany();
        $this->requirePermission('kontor-germany-view');
        $taxId = strtoupper(trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('tax_id')
        )));
        if ($taxId === '' || strlen($taxId) > 32) {
            throw new WireException($this->_('Enter a German VAT ID.'));
        }
        $valid = $this->germanyModule()->localizationProvider()->validateTaxId($taxId);
        $this->wire()->session->set('kontorGermanyTaxResult', [
            'taxId' => $taxId,
            'valid' => $valid,
        ]);
        $this->message($valid
            ? $this->_('VAT ID checksum is valid.')
            : $this->_('VAT ID checksum is invalid.'));
        $this->wire()->session->redirect('../germany/');
    }

    public function ___executeGermanySeedChart(): void
    {
        $this->requirePost();
        $this->requireGermany();
        $this->requirePermission('kontor-germany-configure');
        $seeder = $this->germanyModule()->chartOfAccountsSeeder();
        $before = [];
        foreach ($seeder->definitions() as $definition) {
            $existing = $this->ledgerModule()->accountRepository()->findByCode(
                $this->organizationUid(),
                $definition['code'],
            );
            if ($existing !== null) {
                $before[$existing->uid->toString()] = true;
            }
        }
        $accounts = $seeder->seed(
            $this->organizationUid(),
            (int) $this->wire()->user->id,
        );
        $created = 0;
        foreach ($accounts as $account) {
            if (isset($before[$account->uid->toString()])) {
                continue;
            }
            $created++;
            $this->audit('germany', 'ledger_account', $account->uid->toString(), 'seeded', current: [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
            ]);
        }
        $this->message(sprintf(
            $this->_('German chart ready: %d created, %d already present.'),
            $created,
            count($accounts) - $created,
        ));
        $this->wire()->session->redirect('../germany/');
    }

    public function ___executeGermanyFormat(): void
    {
        $this->requirePost();
        $this->requireGermany();
        $this->requirePermission('kontor-germany-view');
        $invoiceNumber = $this->germanyRequiredTextFromPost('invoice_number', 'Invoice number', 100);
        $issueDate = $this->germanyDateFromPost('issue_date', 'Issue date');
        $dueDateRaw = trim((string) $this->wire()->input->post('due_date'));
        $dueDate = $dueDateRaw !== ''
            ? $this->germanyDateFromPost('due_date', 'Due date')
            : null;
        if ($dueDate !== null && $dueDate < $issueDate) {
            throw new WireException($this->_('Due date cannot be before the issue date.'));
        }
        $sellerTaxId = strtoupper(trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('seller_tax_id')
        )));
        if ($sellerTaxId !== ''
            && !$this->germanyModule()->localizationProvider()->validateTaxId($sellerTaxId)) {
            throw new WireException($this->_('Seller VAT ID checksum is invalid.'));
        }
        $sellerTaxId = str_replace([' ', '-'], '', $sellerTaxId);
        $quantityRaw = str_replace(',', '.', trim((string) $this->wire()->input->post('quantity')));
        if (preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $quantityRaw) !== 1
            || (float) $quantityRaw <= 0) {
            throw new WireException($this->_('Quantity must be positive with at most three decimal places.'));
        }
        $taxRateRaw = str_replace(',', '.', trim((string) $this->wire()->input->post('tax_rate')));
        if (preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $taxRateRaw) !== 1
            || (float) $taxRateRaw > 100) {
            throw new WireException($this->_('Tax rate must be between 0 and 100.'));
        }
        $unitPrice = Money::ofMinor(
            $this->positiveMoneyMinorFromPost('unit_price', 'Unit price'),
            'EUR',
        );
        $lineTotal = $unitPrice->multiply((float) $quantityRaw);
        $totalTax = Money::ofMinor(
            (int) round($lineTotal->amountMinor() * (float) $taxRateRaw / 100),
            'EUR',
        );
        $totalGross = $lineTotal->add($totalTax);
        $buyerCountryCode = strtoupper(
            $this->germanyRequiredTextFromPost('buyer_country_code', 'Buyer country', 2)
        );
        if (preg_match('/^[A-Z]{2}$/', $buyerCountryCode) !== 1) {
            throw new WireException($this->_('Buyer country must be a two-letter code.'));
        }
        $invoice = new LocalizedInvoiceInput(
            invoiceNumber: $invoiceNumber,
            issueDate: $issueDate,
            dueDate: $dueDate,
            currencyCode: 'EUR',
            seller: new LocalizedPartyInput(
                $this->germanyRequiredTextFromPost('seller_name', 'Seller name', 255),
                $this->germanyRequiredTextFromPost('seller_street', 'Seller street', 255),
                $this->germanyRequiredTextFromPost('seller_city', 'Seller city', 100),
                $this->germanyRequiredTextFromPost('seller_postal_code', 'Seller postal code', 20),
                'DE',
                $sellerTaxId ?: null,
            ),
            buyer: new LocalizedPartyInput(
                $this->germanyRequiredTextFromPost('buyer_name', 'Buyer name', 255),
                $this->germanyRequiredTextFromPost('buyer_street', 'Buyer street', 255),
                $this->germanyRequiredTextFromPost('buyer_city', 'Buyer city', 100),
                $this->germanyRequiredTextFromPost('buyer_postal_code', 'Buyer postal code', 20),
                $buyerCountryCode,
            ),
            lineItems: [
                new LocalizedLineItemInput(
                    $this->germanyRequiredTextFromPost('line_description', 'Line description', 255),
                    $quantityRaw,
                    $unitPrice,
                    $lineTotal,
                    $taxRateRaw,
                ),
            ],
            totalNet: $lineTotal,
            totalTax: $totalTax,
            totalGross: $totalGross,
        );
        $xml = $this->germanyModule()->documentFormatter()->format($invoice);
        $this->wire()->session->set('kontorGermanyXmlResult', [
            'invoiceNumber' => $invoiceNumber,
            'xml' => $xml,
            'bytes' => strlen($xml),
            'netMinor' => $lineTotal->amountMinor(),
            'taxMinor' => $totalTax->amountMinor(),
            'grossMinor' => $totalGross->amountMinor(),
        ]);
        $this->message($this->_('XRechnung preview generated.'));
        $this->wire()->session->redirect('../germany/');
    }
}

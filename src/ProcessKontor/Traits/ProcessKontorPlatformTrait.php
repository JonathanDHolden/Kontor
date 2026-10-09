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
trait ProcessKontorPlatformTrait
{

    public function ___executeMarketplace(): string
    {
        $this->requireMarketplace();
        $this->requirePermission('kontor-marketplace-advisory-view');
        $module = $this->marketplaceModule();
        $syncResult = $this->wire()->session->get('kontorMarketplaceSyncResult');
        $this->wire()->session->set('kontorMarketplaceSyncResult', null);
        $installability = [];
        foreach ($module->listingRepository()->all() as $listing) {
            $installability[$listing->registryName . ':' . $listing->package] =
                $module->installabilityChecker()->check(
                    $listing,
                    [],
                    PHP_VERSION,
                    '3.0.269',
                );
        }
        $this->setPageTitle($this->_('Kontor · Marketplace'));

        return $this->renderTemplate('marketplace', [
            'registries' => $module->registryRepository()->all(),
            'listings' => $module->listingRepository()->all(),
            'publishers' => $module->publisherRepository()->all(),
            'advisories' => $module->advisoryRepository()->all(),
            'installability' => $installability,
            'syncResult' => is_array($syncResult) ? $syncResult : null,
            'canManage' => $this->can('kontor-marketplace-registry-manage'),
        ]);
    }

    public function ___executeMarketplaceRegistry(): void
    {
        $this->requirePost();
        $this->requireMarketplace();
        $this->requirePermission('kontor-marketplace-registry-manage');
        $name = strtolower($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('name')
        ));
        $url = trim((string) $this->wire()->input->post('url'));
        if (preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $name) !== 1
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array((string) parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new WireException($this->_('A valid registry name and HTTP URL are required.'));
        }
        $registry = $this->marketplaceModule()->registryManagement()->registerCustomRegistry(
            $name,
            mb_substr($url, 0, 2048),
            (bool) $this->wire()->input->post('trusted'),
        );
        $this->audit('marketplace', 'registry', $registry->name, 'created');
        $this->message($this->_('Custom registry added.'));
        $this->wire()->session->redirect('../marketplace/');
    }

    public function ___executeMarketplaceRegistryToggle(): void
    {
        $this->requirePost();
        $this->requireMarketplace();
        $this->requirePermission('kontor-marketplace-registry-manage');
        $name = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('registry_name')
        );
        $registry = $this->marketplaceModule()->registryRepository()->require($name);
        if ($registry->status === 'active') {
            $this->marketplaceModule()->registryManagement()->disable($name);
            $action = 'disabled';
        } else {
            $this->marketplaceModule()->registryManagement()->enable($name);
            $action = 'enabled';
        }
        $this->audit('marketplace', 'registry', $name, $action);
        $this->message(sprintf($this->_('Registry %s.'), $action));
        $this->wire()->session->redirect('../marketplace/');
    }

    public function ___executeMarketplaceImport(): void
    {
        $this->requirePost();
        $this->requireMarketplace();
        $this->requirePermission('kontor-marketplace-registry-manage');
        $registryName = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('registry_name')
        );
        $payload = trim((string) $this->wire()->input->post('payload_json'));
        if ($payload === '' || strlen($payload) > 1048576) {
            throw new WireException($this->_('Registry JSON is required and must be at most 1 MB.'));
        }
        try {
            $result = $this->marketplaceModule()->syncService()->syncPayload(
                $registryName,
                $payload,
            );
        } catch (\JsonException) {
            throw new WireException($this->_('Registry payload is not valid JSON.'));
        }
        $this->audit('marketplace', 'registry', $registryName, 'synchronized', metadata: [
            'listings' => $result->listingsSynced,
            'advisories' => $result->advisoriesSynced,
            'skipped' => count($result->skipped),
        ]);
        $this->wire()->session->set('kontorMarketplaceSyncResult', [
            'listings' => $result->listingsSynced,
            'advisories' => $result->advisoriesSynced,
            'skipped' => $result->skipped,
        ]);
        $this->message($this->_('Registry payload synchronized.'));
        $this->wire()->session->redirect('../marketplace/');
    }

    public function ___executeGraphql(): string
    {
        $this->requireGraphql();
        $this->requirePermission('kontor-api-token-manage');
        $result = $this->wire()->session->get('kontorGraphqlResult');
        $this->wire()->session->set('kontorGraphqlResult', null);
        $this->setPageTitle($this->_('Kontor · GraphQL explorer'));

        return $this->renderTemplate('graphql', [
            'schema' => $this->graphqlModule()->schemaRegistry()->toSdl(),
            'types' => $this->graphqlModule()->schemaRegistry()->objectTypes(),
            'result' => is_array($result) ? $result : null,
            'sampleQuery' => "{\n  organizations(pageSize: 10) {\n    uid\n    name\n    status\n  }\n}",
        ]);
    }

    public function ___executeGraphqlRun(): void
    {
        $this->requirePost();
        $this->requireGraphql();
        $this->requirePermission('kontor-api-token-manage');
        $token = trim((string) $this->wire()->input->post('token'));
        $query = trim((string) $this->wire()->input->post('query'));
        if ($token === '' || $query === '') {
            throw new WireException($this->_('Bearer token and GraphQL query are required.'));
        }
        $request = new \Kontor\API\DTO\ApiHttpRequest(
            method: 'POST',
            path: 'graphql',
            queryParams: [],
            headers: ['authorization' => 'Bearer ' . $token],
            body: json_encode(['query' => $query], JSON_THROW_ON_ERROR),
        );
        $response = $this->graphqlModule()->requestHandler()->handle($request);
        $decoded = json_decode($response->body, true);
        $this->wire()->session->set('kontorGraphqlResult', [
            'status' => $response->status,
            'body' => is_array($decoded) ? $decoded : ['raw' => $response->body],
            'query' => $query,
        ]);
        $this->audit('graphql', 'query', Uid::generate()->toString(), 'executed', metadata: [
            'status' => $response->status,
            'hasErrors' => isset($decoded['errors']) && $decoded['errors'] !== [],
        ]);
        $this->wire()->session->redirect('../graphql/');
    }

    public function ___executeApi(): string
    {
        $this->requireApi();
        $this->requirePermission('kontor-api-token-manage');
        $module = $this->apiModule();
        $subscriptions = $this->can('kontor-api-webhook-manage')
            ? $module->webhookSubscriptionRepository()->forOrganization($this->organizationUid())
            : [];
        $deliveries = [];
        foreach ($subscriptions as $subscription) {
            foreach ($module->webhookDeliveryRepository()->forSubscription(
                $subscription->uid->toString()
            ) as $delivery) {
                $deliveries[] = $delivery;
            }
        }
        usort(
            $deliveries,
            static fn ($left, $right): int => $right->createdAt <=> $left->createdAt,
        );
        $secret = $this->wire()->session->get('kontorApiOneTimeSecret');
        $this->wire()->session->set('kontorApiOneTimeSecret', null);
        $this->setPageTitle($this->_('Kontor · API access'));

        return $this->renderTemplate('api', [
            'tokens' => $module->tokenRepository()->forOrganization($this->organizationUid()),
            'subscriptions' => $subscriptions,
            'deliveries' => array_slice($deliveries, 0, 25),
            'resources' => $module->resourceRegistry()->all(),
            'openApi' => $module->openApiGenerator()->generate(),
            'oneTimeSecret' => is_array($secret) ? $secret : null,
            'canManageWebhooks' => $this->can('kontor-api-webhook-manage'),
        ]);
    }

    public function ___executeApiToken(): void
    {
        $this->requirePost();
        $this->requireApi();
        $this->requirePermission('kontor-api-token-manage');
        $name = trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('name')
        ));
        $rawScopes = $this->wire()->input->post('scopes');
        $scopeValues = is_array($rawScopes)
            ? $rawScopes
            : explode(',', (string) $rawScopes);
        $scopes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $scope): string => trim(strtolower((string) $scope)),
            $scopeValues,
        ))));
        $accessMode = strtolower($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('access_mode')
        ));
        if ($accessMode === 'unrestricted') {
            $scopes = [];
        } elseif ($accessMode === 'restricted' && $scopes === []) {
            throw new WireException($this->_('Choose at least one API permission.'));
        }
        $expires = trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('expires_at')
        ));
        if ($name === '') {
            throw new WireException($this->_('Token name is required.'));
        }
        try {
            $expiresAt = $expires !== '' ? new \DateTimeImmutable($expires) : null;
        } catch (\Exception) {
            throw new WireException($this->_('Token expiry is invalid.'));
        }
        $issued = $this->apiModule()->authenticator()->issue(
            $this->organizationUid(),
            mb_substr($name, 0, 255),
            $scopes,
            $expiresAt,
            (int) $this->wire()->user->id,
        );
        $this->audit('api', 'token', $issued->token->uid->toString(), 'issued');
        $this->wire()->session->set('kontorApiOneTimeSecret', [
            'kind' => 'token',
            'label' => $issued->token->name,
            'value' => $issued->plaintext,
        ]);
        $this->message($this->_('API token issued.'));
        $this->wire()->session->redirect('../api/');
    }

    public function ___executeApiTokenRevoke(): void
    {
        $this->requirePost();
        $this->requireApi();
        $this->requirePermission('kontor-api-token-manage');
        $uid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('token_uid')
        );
        $token = $this->apiModule()->tokenRepository()->require($uid);
        $this->requireSameOrganization($token->organizationId);
        $this->apiModule()->authenticator()->revoke($uid);
        $this->audit('api', 'token', $uid, 'revoked');
        $this->message($this->_('API token revoked.'));
        $this->wire()->session->redirect('../api/');
    }

    public function ___executeApiWebhook(): void
    {
        $this->requirePost();
        $this->requireApi();
        $this->requirePermission('kontor-api-webhook-manage');
        $url = trim((string) $this->wire()->input->post('url'));
        $event = strtolower($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('event_pattern')
        ));
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array((string) parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            || preg_match('/^[a-z][a-z0-9._-]{1,149}$/', $event) !== 1) {
            throw new WireException($this->_('A valid HTTP URL and event name are required.'));
        }
        $secret = 'whsec_' . bin2hex(random_bytes(24));
        $subscription = \Kontor\API\Domain\WebhookSubscription::create(
            $this->organizationUid(),
            mb_substr($url, 0, 2048),
            $event,
            $secret,
            (int) $this->wire()->user->id,
        );
        $this->apiModule()->webhookSubscriptionRepository()->save($subscription);
        $this->audit('api', 'webhook', $subscription->uid->toString(), 'created');
        $this->wire()->session->set('kontorApiOneTimeSecret', [
            'kind' => 'webhook',
            'label' => $event,
            'value' => $secret,
        ]);
        $this->message($this->_('Webhook subscription created.'));
        $this->wire()->session->redirect('../api/');
    }

    public function ___executeApiWebhookArchive(): void
    {
        $this->requirePost();
        $this->requireApi();
        $this->requirePermission('kontor-api-webhook-manage');
        $uid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('subscription_uid')
        );
        $subscription = $this->apiModule()->webhookSubscriptionRepository()->require($uid);
        $this->requireSameOrganization($subscription->organizationId);
        $this->apiModule()->webhookSubscriptionRepository()->archive($uid);
        $this->audit('api', 'webhook', $uid, 'archived');
        $this->message($this->_('Webhook subscription archived.'));
        $this->wire()->session->redirect('../api/');
    }

    public function ___executeCustomEntities(): string
    {
        $this->requireEntities();
        $this->requirePermission('kontor-entities-record-view');
        $module = $this->entitiesModule();
        $definitions = array_values(array_filter(
            $module->definitionRepository()->forOrganization($this->organizationUid()),
            static fn ($definition): bool => $definition->isActive(),
        ));
        $recordCounts = [];
        $fieldCounts = [];
        $viewCounts = [];
        foreach ($definitions as $definition) {
            $definitionUid = $definition->uid->toString();
            $recordCounts[$definitionUid] = $module->recordRepository()->countForDefinition($definitionUid);
            $fieldCounts[$definitionUid] = count($module->fieldRepository()->forDefinition($definitionUid));
            $viewCounts[$definitionUid] = count($module->viewRepository()->forDefinition($definitionUid));
        }
        $this->setPageTitle($this->_('Kontor · Custom entities'));

        return $this->renderTemplate('custom-entities', [
            'definitions' => $definitions,
            'recordCounts' => $recordCounts,
            'fieldCounts' => $fieldCounts,
            'viewCounts' => $viewCounts,
            'query' => trim($this->wire()->sanitizer->text((string) $this->wire()->input->get('q'))),
            'canManage' => $this->can('kontor-entities-definition-manage'),
        ]);
    }

    public function ___executeCustomEntity(): string
    {
        $this->requireEntities();
        $module = $this->entitiesModule();
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->get('id'));
        $definition = $id !== '' ? $module->definitionRepository()->require($id) : null;
        if ($definition !== null) {
            $this->requireSameOrganization($definition->organizationId);
            $this->requireEntityViewPermission($definition);
        } else {
            $this->requirePermission('kontor-entities-definition-manage');
        }
        $values = [
            'entityKey' => '',
            'name' => '',
            'viewPermission' => 'kontor-entities-record-view',
            'editPermission' => 'kontor-entities-record-manage',
            'apiExposed' => false,
        ];
        $error = '';
        if ($definition === null && $this->wire()->input->post('submit_save')) {
            $this->requirePost();
            $values = [
                'entityKey' => strtolower($this->wire()->sanitizer->text(
                    (string) $this->wire()->input->post('entity_key')
                )),
                'name' => trim($this->wire()->sanitizer->text(
                    (string) $this->wire()->input->post('name')
                )),
                'viewPermission' => strtolower($this->wire()->sanitizer->text(
                    (string) $this->wire()->input->post('view_permission')
                )),
                'editPermission' => strtolower($this->wire()->sanitizer->text(
                    (string) $this->wire()->input->post('edit_permission')
                )),
                'apiExposed' => (bool) $this->wire()->input->post('api_exposed'),
            ];
            if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $values['entityKey']) !== 1) {
                $error = $this->_('Entity key must be a lowercase identifier.');
            } elseif ($values['name'] === '') {
                $error = $this->_('Entity name is required.');
            }
            if ($error === '') {
                try {
                    $definition = $module->builder()->defineEntity(
                        $this->organizationUid(),
                        $values['entityKey'],
                        mb_substr($values['name'], 0, 191),
                        $values['viewPermission'] ?: null,
                        $values['editPermission'] ?: null,
                        $values['apiExposed'],
                        (int) $this->wire()->user->id,
                    );
                } catch (\PDOException $exception) {
                    $error = $exception->getCode() === '23000'
                        ? $this->_('That entity key is already in use.')
                        : $this->_('Entity definition could not be saved.');
                }
                if ($error === '') {
                    $this->audit('entities', 'definition', $definition->uid->toString(), 'created');
                    $this->message($this->_('Custom entity created.'));
                    $this->wire()->session->redirect(
                        '../custom-entity/?id=' . rawurlencode($definition->uid->toString())
                    );
                }
            }
        }

        $fields = $definition !== null
            ? $module->fieldRepository()->forDefinition($definition->uid->toString())
            : [];
        $views = $definition !== null
            ? $module->viewRepository()->forDefinition($definition->uid->toString())
            : [];
        $viewId = $this->wire()->sanitizer->text((string) $this->wire()->input->get('view'));
        $selectedView = $viewId !== '' ? $module->viewRepository()->require($viewId) : null;
        if ($selectedView !== null && ($definition === null
            || $selectedView->definitionUid !== $definition->uid->toString()
            || $selectedView->organizationId !== $this->organizationUid())) {
            throw new WirePermissionException($this->_('Saved view does not belong to this entity.'));
        }
        $records = $definition !== null
            ? ($selectedView !== null
                ? $module->views()->apply($selectedView)
                : $module->recordRepository()->forDefinition($definition->uid->toString()))
            : [];
        $this->setPageTitle($definition === null
            ? $this->_('Kontor · Create data workspace')
            : sprintf($this->_('Kontor · %s'), $definition->name));

        return $this->renderTemplate('custom-entity', [
            'definition' => $definition,
            'values' => $values,
            'error' => $error,
            'fields' => $fields,
            'views' => $views,
            'selectedView' => $selectedView,
            'records' => $records,
            'schema' => $definition !== null ? $module->schema()->describe($definition->uid->toString()) : null,
            'canDefine' => $this->can('kontor-entities-definition-manage'),
            'canManageRecords' => $definition !== null && $this->canEditEntity($definition),
            'canManageViews' => $this->can('kontor-entities-view-manage'),
        ]);
    }

    public function ___executeCustomEntityField(): void
    {
        $this->requirePost();
        $this->requireEntities();
        $this->requirePermission('kontor-entities-definition-manage');
        $definition = $this->requireEntityDefinitionFromPost();
        $key = strtolower($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('field_key')
        ));
        $label = trim($this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('label')
        ));
        $type = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('field_type'),
            \Kontor\Entities\Domain\EntityField::TYPES,
        );
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1 || $label === '' || $type === null) {
            throw new WireException($this->_('Field key, label, and supported type are required.'));
        }
        $field = $this->entitiesModule()->builder()->addField(
            $definition->uid->toString(),
            $key,
            mb_substr($label, 0, 191),
            $type,
            (bool) $this->wire()->input->post('required'),
        );
        $this->audit('entities', 'field', $field->uid->toString(), 'created');
        $this->message($this->_('Entity field added.'));
        $this->redirectToEntityDefinition($definition->uid->toString());
    }

    public function ___executeCustomEntityRecord(): string
    {
        $this->requireEntities();
        $module = $this->entitiesModule();
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->get('id'));
        $record = $id !== '' ? $module->recordRepository()->require($id) : null;
        $definitionId = $record?->definitionUid
            ?? $this->wire()->sanitizer->text(
                (string) (
                    $this->wire()->input->get('definition')
                    ?: $this->wire()->input->post('definition_uid')
                )
            );
        $definition = $module->definitionRepository()->require($definitionId);
        $this->requireSameOrganization($definition->organizationId);
        $this->requireEntityViewPermission($definition);
        if ($record !== null) {
            $this->requireSameOrganization($record->organizationId);
        }
        $fields = $module->fieldRepository()->forDefinition($definitionId);
        $error = '';
        if ($this->wire()->input->post('submit_save')) {
            $this->requirePost();
            $this->requireEntityEditPermission($definition);
            try {
                $data = $this->entityRecordDataFromPost($fields);
                $record = $record === null
                    ? $module->records()->create($definitionId, $data, (int) $this->wire()->user->id)
                    : $module->records()->update($record->uid->toString(), $data);
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
            if ($error === '') {
                $this->audit(
                    'entities',
                    'record',
                    $record->uid->toString(),
                    $id === '' ? 'created' : 'updated',
                );
                $this->message($this->_('Custom entity record saved.'));
                $this->wire()->session->redirect(
                    '../custom-entity-record/?id=' . rawurlencode($record->uid->toString())
                );
            }
        }
        $relations = $record !== null
            ? $module->relations()->relatedTo(
                $this->organizationUid(),
                $definition->entityKey,
                $record->uid->toString(),
            )
            : [];
        $this->setPageTitle(sprintf(
            $this->_('Kontor · %s record'),
            $definition->name,
        ));

        return $this->renderTemplate('custom-entity-record', [
            'definition' => $definition,
            'record' => $record,
            'fields' => $fields,
            'error' => $error,
            'relations' => $relations,
            'canEdit' => $this->canEditEntity($definition),
        ]);
    }

    public function ___executeCustomEntityView(): void
    {
        $this->requirePost();
        $this->requireEntities();
        $this->requirePermission('kontor-entities-view-manage');
        $definition = $this->requireEntityDefinitionFromPost();
        $fields = $this->entitiesModule()->fieldRepository()->forDefinition(
            $definition->uid->toString()
        );
        $fieldKeys = array_map(static fn ($field): string => $field->fieldKey, $fields);
        $name = trim($this->wire()->sanitizer->text((string) $this->wire()->input->post('name')));
        $filterField = $this->wire()->sanitizer->text((string) $this->wire()->input->post('filter_field'));
        $filterOperator = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('filter_operator'),
            ['equals', 'not_equals', 'greater_than', 'less_than', 'contains'],
        );
        $filterValue = $this->wire()->sanitizer->text((string) $this->wire()->input->post('filter_value'));
        $sortField = $this->wire()->sanitizer->text((string) $this->wire()->input->post('sort_field'));
        $sortDirection = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->post('sort_direction'),
            ['asc', 'desc'],
        ) ?? 'asc';
        if ($name === '' || ($filterField !== '' && !in_array($filterField, $fieldKeys, true))
            || ($sortField !== '' && !in_array($sortField, $fieldKeys, true))) {
            throw new WireException($this->_('Saved-view configuration is invalid.'));
        }
        $view = $this->entitiesModule()->views()->createView(
            $this->organizationUid(),
            $definition->uid->toString(),
            mb_substr($name, 0, 191),
            $filterField !== '' ? [[
                'field' => $filterField,
                'operator' => $filterOperator ?? 'equals',
                'value' => $filterValue,
            ]] : [],
            $sortField !== '' ? [['field' => $sortField, 'direction' => $sortDirection]] : [],
            $fieldKeys,
            (int) $this->wire()->user->id,
        );
        $this->audit('entities', 'view', $view->uid->toString(), 'created');
        $this->message($this->_('Saved view created.'));
        $this->wire()->session->redirect(
            '../custom-entity/?id=' . rawurlencode($definition->uid->toString())
                . '&view=' . rawurlencode($view->uid->toString())
        );
    }

    public function ___executeCustomEntityRelation(): void
    {
        $this->requirePost();
        $this->requireEntities();
        $recordUid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('record_uid')
        );
        $record = $this->entitiesModule()->recordRepository()->require($recordUid);
        $definition = $this->entitiesModule()->definitionRepository()->require($record->definitionUid);
        $this->requireSameOrganization($record->organizationId);
        $this->requireEntityEditPermission($definition);
        $targetType = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('target_type')
        );
        $targetUid = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('target_uid')
        );
        $relationType = $this->wire()->sanitizer->text(
            (string) $this->wire()->input->post('relation_type')
        );
        if ($targetType === '' || $targetUid === '' || $relationType === '') {
            throw new WireException($this->_('Relation target and type are required.'));
        }
        $relationUid = $this->entitiesModule()->relations()->linkRecords(
            $this->organizationUid(),
            $definition->entityKey,
            $recordUid,
            $targetType,
            $targetUid,
            $relationType,
            'directed',
        );
        $this->audit('entities', 'relation', $relationUid, 'created');
        $this->message($this->_('Record relation created.'));
        $this->wire()->session->redirect(
            '../custom-entity-record/?id=' . rawurlencode($recordUid)
        );
    }

    public function ___executeCompany(): string
    {
        $this->requireContacts();
        $id = $this->wire()->sanitizer->text((string) $this->wire()->input->get('id'));
        $company = $id !== '' ? $this->companyRepository()->require($id) : null;
        $this->requirePermission($company === null
            ? 'kontor-contacts-company-create'
            : 'kontor-contacts-company-edit');
        $this->setPageTitle($company === null
            ? $this->_('Kontor · New company')
            : sprintf($this->_('Kontor · %s'), $company->legalName));

        $isNew = $company === null;
        $previous = $company === null ? null : $this->companyAuditSnapshot($company);
        $form = $this->buildCompanyForm($company);

        if ($this->wire()->input->post('submit_save')) {
            $this->requirePost();
            $form->processInput($this->wire()->input->post);

            if (!$form->getErrors()) {
                $company = $this->saveCompanyFromForm($form, $company);
                $this->audit(
                    component: 'contacts',
                    entityType: 'company',
                    entityUid: $company->uid->toString(),
                    action: $isNew ? 'created' : 'updated',
                    previous: $previous,
                    current: $this->companyAuditSnapshot($company),
                );
                $this->message($this->_('Company saved.'));
                $this->wire()->session->redirect('../company/?id=' . rawurlencode($company->uid->toString()));
            }
        }

        $relationships = $company === null ? [] : $this->companyRelationships($company);
        $companyFiles = $company !== null
            && $this->filesReady()
            && $this->can('kontor-files-file-view')
            ? $this->filesModule()->fileManager()->forEntity(
                'company',
                $company->uid->toString(),
                $this->organizationUid(),
            )
            : [];

        return $this->renderTemplate('entity-form', [
            'form' => $form,
            'backUrl' => '../companies/',
            'backLabel' => $this->_('Back to companies'),
            'eyebrow' => $this->_('Companies'),
            'title' => $company?->legalName ?: $this->_('Create company'),
            'description' => $company === null
                ? $this->_('Add an organization, customer or partner.')
                : $this->_('Manage commercial identity and contact information.'),
            'entity' => $company,
            'entityType' => 'company',
            'relationships' => $relationships,
            'availableCompanies' => [],
            'addresses' => $company === null
                ? []
                : $this->addressRepository()->forOwner('company', $company->uid->toString()),
            'duplicates' => [],
            'tags' => $company === null
                ? []
                : $this->tagService()->tagsFor(
                    $this->organizationUid(),
                    'company',
                    $company->uid->toString()
                ),
            'workspace' => $company === null
                ? $this->emptyCustomerWorkspace()
                : $this->customerWorkspace('company', $company->uid->toString()),
            'companyFiles' => $companyFiles,
            'canViewCompanyFiles' => $company !== null
                && $this->filesReady()
                && $this->can('kontor-files-file-view'),
        ]);
    }

    public function ___executeSearch(): string
    {
        $this->requireSearch();
        $this->setPageTitle($this->_('Kontor · Search'));
        $query = $this->wire()->sanitizer->text((string) $this->wire()->input->get('q'));
        $availableEntityTypes = [];

        if ($this->contactsReady() && $this->can('kontor-contacts-contact-view')) {
            $availableEntityTypes['contact'] = $this->_('Contacts');
        }
        if ($this->contactsReady() && $this->can('kontor-contacts-company-view')) {
            $availableEntityTypes['company'] = $this->_('Companies');
        }

        if ($this->crmReady() && $this->can('kontor-crm-lead-view')) {
            $availableEntityTypes['lead'] = $this->_('Leads');
        }
        if ($this->crmReady() && $this->can('kontor-crm-deal-view')) {
            $availableEntityTypes['deal'] = $this->_('Deals');
        }

        if ($this->catalogReady() && $this->can('kontor-catalog-item-view')) {
            $availableEntityTypes['catalog_item'] = $this->_('Catalog items');
        }
        if ($this->can('kontor-components-view')) {
            $availableEntityTypes['component'] = $this->_('Components');
        }

        $entityType = $this->wire()->sanitizer->option(
            (string) $this->wire()->input->get('type'),
            array_keys($availableEntityTypes)
        ) ?? '';
        $pageSize = 20;
        $page = max(1, (int) $this->wire()->input->get('page'));
        $totalPages = 1;
        $result = null;

        if (mb_strlen($query) >= 2 && $availableEntityTypes !== []) {
            $result = $this->searchService()->search(new SearchQuery(
                organizationId: $this->organizationUid(),
                term: $query,
                entityTypes: $entityType !== '' ? [$entityType] : array_keys($availableEntityTypes),
                limit: $pageSize,
                offset: ($page - 1) * $pageSize,
            ));
            $totalPages = max(1, (int) ceil($result->total / $pageSize));

            if ($page > $totalPages) {
                $page = $totalPages;
                $result = $this->searchService()->search(new SearchQuery(
                    organizationId: $this->organizationUid(),
                    term: $query,
                    entityTypes: $entityType !== '' ? [$entityType] : array_keys($availableEntityTypes),
                    limit: $pageSize,
                    offset: ($page - 1) * $pageSize,
                ));
            }
        }

        return $this->renderTemplate('search', [
            'query' => $query,
            'result' => $result,
            'selectedEntityType' => $entityType,
            'availableEntityTypes' => $availableEntityTypes,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }
}

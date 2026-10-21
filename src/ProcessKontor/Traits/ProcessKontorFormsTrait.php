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
trait ProcessKontorFormsTrait
{

    private function buildContactForm(?Contact $contact): InputfieldForm
    {
        /** @var InputfieldForm $form */
        $form = $this->wire()->modules->get('InputfieldForm');
        $form->action = './' . ($contact ? '?id=' . rawurlencode($contact->uid->toString()) : '');
        $form->addClass('InputfieldFormFocusFirst kontor-entity-form');

        $this->addTextField($form, 'display_name', $this->_('Display name'), $contact?->displayName, true, 100);
        $this->addTextField($form, 'first_name', $this->_('First name'), $contact?->firstName, false, 50);
        $this->addTextField($form, 'last_name', $this->_('Last name'), $contact?->lastName, false, 50);
        $this->addEmailField($form, 'email', $this->_('Email'), $contact?->email, 50);
        $this->addTextField($form, 'linkedin', $this->_('LinkedIn'), (string) ($contact?->metadata['linkedin'] ?? ''), false, 255);
        $this->addTextField($form, 'phone', $this->_('Phone'), $contact?->phone, false, 50);
        $this->addTextField($form, 'mobile', $this->_('Mobile'), $contact?->mobile, false, 50);
        $this->addTextField($form, 'job_title', $this->_('Job title'), $contact?->jobTitle, false, 50);
        $this->addStatusField($form, $contact?->status ?? 'active');
        $this->addTextareaField($form, 'notes', $this->_('Notes'), $contact?->notes);
        $this->addSubmit($form, $this->_('Save contact'));

        return $form;
    }

    private function buildCompanyForm(?Company $company): InputfieldForm
    {
        /** @var InputfieldForm $form */
        $form = $this->wire()->modules->get('InputfieldForm');
        $form->action = './' . ($company ? '?id=' . rawurlencode($company->uid->toString()) : '');
        $form->addClass('InputfieldFormFocusFirst kontor-entity-form');

        $this->addTextField($form, 'legal_name', $this->_('Legal name'), $company?->legalName, true, 100);
        $this->addTextField($form, 'trading_name', $this->_('Trading name'), $company?->tradingName, false, 50);
        $this->addTextField($form, 'registration_number', $this->_('Registration number'), $company?->registrationNumber, false, 50);
        $this->addEmailField($form, 'email', $this->_('Email'), $company?->email, 50);
        $this->addTextField($form, 'phone', $this->_('Phone'), $company?->phone, false, 50);
        $this->addTextField($form, 'website', $this->_('Website'), $company?->website, false, 50);
        $this->addTextField($form, 'vat_number', $this->_('VAT number'), $company?->vatNumber, false, 50);
        $this->addStatusField($form, $company?->status ?? 'active');
        $this->addTextareaField($form, 'notes', $this->_('Notes'), $company?->notes);
        $this->addSubmit($form, $this->_('Save company'));

        return $form;
    }

    private function buildCatalogItemForm(?CatalogItem $item): InputfieldForm
    {
        /** @var InputfieldForm $form */
        $form = $this->wire()->modules->get('InputfieldForm');
        $form->action = './' . ($item ? '?id=' . rawurlencode($item->uid->toString()) : '');
        $form->addClass('InputfieldFormFocusFirst kontor-entity-form');
        $language = $this->organization()->defaultLanguage;
        $languages = $this->catalogFormLanguages();
        $currency = $this->organization()->defaultCurrency;

        $this->addSelectField(
            $form,
            'item_type',
            $this->_('Item type'),
            ['product' => $this->_('Product'), 'service' => $this->_('Service')],
            $item?->itemType ?? 'product',
            25,
        );
        $this->addSelectField(
            $form,
            'status',
            $this->_('Status'),
            [
                'active' => $this->_('Active'),
                'inactive' => $this->_('Inactive'),
                'discontinued' => $this->_('Discontinued'),
            ],
            $item?->status ?? 'active',
            25,
        );
        foreach ($languages as $locale => $label) {
            $this->addTextField(
                $form,
                'title_' . $locale,
                sprintf(
                    $locale === $language
                        ? $this->_('Title (%s) · Default')
                        : $this->_('Title (%s)'),
                    strtoupper($locale)
                ),
                $item?->title[$locale] ?? null,
                $locale === $language,
                $locale === $language ? 50 : 33,
            );

            if ($locale !== $language && ($item?->title[$locale] ?? '') === '') {
                $translation = $form->getChildByName('title_' . $locale);

                if ($translation !== null) {
                    $translation->collapsed = Inputfield::collapsedYes;
                }
            }
        }
        $this->addTextField($form, 'sku', $this->_('SKU'), $item?->sku, false, 50);
        $this->addTextField($form, 'barcode', $this->_('Barcode'), $item?->barcode, false, 50);
        $this->addSelectField(
            $form,
            'unit_code',
            $this->_('Unit'),
            $this->catalogUnitOptions($item),
            $item?->unitCode ?? 'pcs',
            25,
        );

        /** @var InputfieldSelect $tax */
        $tax = $this->wire()->modules->get('InputfieldSelect');
        $tax->name = 'tax_code';
        $tax->label = $this->_('Tax code');
        $tax->addOption('', $this->_('Not specified'));
        $tax->addOptions((new TaxCode())->all());
        $tax->value = $item?->taxCode ?? '';
        $tax->columnWidth = 25;
        $form->add($tax);

        /** @var InputfieldSelect $categoryField */
        $categoryField = $this->wire()->modules->get('InputfieldSelect');
        $categoryField->name = 'category_uid';
        $categoryField->label = $this->_('Category');
        $categoryField->addOption('', $this->_('Uncategorized'));
        $categoryOptions = [];

        foreach ($this->categoryRepository()->findAll($this->organizationUid(), limit: 250) as $category) {
            $categoryOptions[$category->uid->toString()] = $this->categoryName($category);
        }

        if ($item?->categoryUid !== null && !isset($categoryOptions[$item->categoryUid])) {
            $assigned = $this->categoryRepository()->find($item->categoryUid);

            if ($assigned !== null && hash_equals($assigned->organizationId, $this->organizationUid())) {
                $categoryOptions[$item->categoryUid] = $this->categoryName($assigned) . ' · archived';
            }
        }

        foreach ($categoryOptions as $uid => $label) {
            $categoryField->addOption($uid, $label);
        }

        $categoryField->value = $item?->categoryUid ?? '';
        $categoryField->columnWidth = 50;
        $form->add($categoryField);

        $currencies = array_fill_keys(
            array_values(array_unique([$currency, 'EUR', 'USD', 'GBP', 'CHF', 'CAD', 'AUD'])),
            '',
        );
        $currencies = array_combine(array_keys($currencies), array_keys($currencies)) ?: [];

        foreach ([
            'sales' => [$this->_('Sales price'), $item?->salesPrice],
            'purchase' => [$this->_('Purchase price'), $item?->purchasePrice],
            'cost' => [$this->_('Cost price'), $item?->costPrice],
        ] as $prefix => [$label, $money]) {
            $this->addTextField(
                $form,
                $prefix . '_price',
                $label,
                $this->moneyFormValue($money),
                false,
                35,
            );
            $this->addSelectField(
                $form,
                $prefix . '_currency',
                $this->_('Currency'),
                $currencies,
                $money?->currencyCode() ?? $currency,
                15,
            );
        }

        /** @var InputfieldCheckbox $inventory */
        $inventory = $this->wire()->modules->get('InputfieldCheckbox');
        $inventory->name = 'track_inventory';
        $inventory->label = $this->_('Track inventory for this item');
        $inventory->checked = $item?->trackInventory ?? false;
        $inventory->columnWidth = 50;
        $form->add($inventory);
        foreach ($languages as $locale => $label) {
            $this->addTextareaField(
                $form,
                'description_' . $locale,
                sprintf(
                    $locale === $language
                        ? $this->_('Description (%s) · Default')
                        : $this->_('Description (%s)'),
                    strtoupper($locale)
                ),
                $item?->description[$locale] ?? null,
                $locale === $language ? 100 : 50,
            );

            if ($locale !== $language && ($item?->description[$locale] ?? '') === '') {
                $translation = $form->getChildByName('description_' . $locale);

                if ($translation !== null) {
                    $translation->collapsed = Inputfield::collapsedYes;
                }
            }
        }
        $this->addSubmit($form, $this->_('Save catalog item'));

        return $form;
    }

    private function buildCatalogCategoryForm(?Category $category): InputfieldForm
    {
        /** @var InputfieldForm $form */
        $form = $this->wire()->modules->get('InputfieldForm');
        $form->action = './' . ($category ? '?id=' . rawurlencode($category->uid->toString()) : '');
        $form->addClass('InputfieldFormFocusFirst kontor-entity-form');
        $language = $this->organization()->defaultLanguage;

        foreach ($this->catalogFormLanguages() as $locale => $label) {
            $this->addTextField(
                $form,
                'name_' . $locale,
                sprintf(
                    $locale === $language
                        ? $this->_('Name (%s) · Default')
                        : $this->_('Name (%s)'),
                    strtoupper($locale)
                ),
                $category?->name[$locale] ?? null,
                $locale === $language,
                50,
            );
        }

        /** @var InputfieldSelect $parent */
        $parent = $this->wire()->modules->get('InputfieldSelect');
        $parent->name = 'parent_uid';
        $parent->label = $this->_('Parent category');
        $parent->addOption('', $this->_('Top level'));

        foreach ($this->categoryRepository()->findAll($this->organizationUid(), limit: 250) as $candidate) {
            if ($candidate->uid->toString() !== $category?->uid->toString()) {
                $parent->addOption($candidate->uid->toString(), $this->categoryName($candidate));
            }
        }

        $parent->value = $category?->parentUid ?? '';
        $parent->columnWidth = 50;
        $form->add($parent);
        $this->addTextField(
            $form,
            'sort_order',
            $this->_('Sort order'),
            (string) ($category?->sortOrder ?? 0),
            true,
            50,
        );
        $this->addSelectField(
            $form,
            'status',
            $this->_('Status'),
            ['active' => $this->_('Active'), 'inactive' => $this->_('Inactive')],
            $category?->status ?? 'active',
            50,
        );
        $this->addSubmit($form, $this->_('Save category'));

        return $form;
    }

    private function buildCatalogPriceListForm(?PriceList $priceList): InputfieldForm
    {
        /** @var InputfieldForm $form */
        $form = $this->wire()->modules->get('InputfieldForm');
        $form->action = './' . ($priceList ? '?id=' . rawurlencode($priceList->uid->toString()) : '');
        $form->addClass('InputfieldFormFocusFirst kontor-entity-form');
        $currency = $priceList?->currencyCode ?? $this->organization()->defaultCurrency;
        $currencies = array_values(array_unique([$currency, 'EUR', 'USD', 'GBP', 'CHF', 'CAD', 'AUD']));
        $currencyOptions = array_combine($currencies, $currencies) ?: [];

        $this->addTextField($form, 'name', $this->_('Name'), $priceList?->name, true, 50);
        $this->addSelectField(
            $form,
            'currency_code',
            $this->_('Currency'),
            $currencyOptions,
            $currency,
            25,
        );
        $this->addSelectField(
            $form,
            'status',
            $this->_('Status'),
            ['active' => $this->_('Active'), 'inactive' => $this->_('Inactive')],
            $priceList?->status ?? 'active',
            25,
        );
        $this->addTextField(
            $form,
            'valid_from',
            $this->_('Valid from (YYYY-MM-DD)'),
            $priceList?->validFrom?->format('Y-m-d'),
            false,
            50,
        );
        $this->addTextField(
            $form,
            'valid_to',
            $this->_('Valid to (YYYY-MM-DD)'),
            $priceList?->validTo?->format('Y-m-d'),
            false,
            50,
        );
        $this->addSubmit($form, $this->_('Save price list'));

        return $form;
    }

    private function buildCatalogPriceEntryForm(
        PriceList $priceList,
        ?PriceListEntry $entry,
        ?string $prefillItemUid = null,
    ): InputfieldForm {
        /** @var InputfieldForm $form */
        $form = $this->wire()->modules->get('InputfieldForm');
        $query = ['list' => $priceList->uid->toString()];

        if ($entry !== null) {
            $query['item'] = $entry->itemUid;
            $query['quantity'] = $this->quantityFormValue($entry->minQuantity);
        } elseif ($prefillItemUid !== null) {
            $query['item'] = $prefillItemUid;
        }

        $form->action = './?' . http_build_query($query);
        $form->addClass('InputfieldFormFocusFirst kontor-entity-form');
        /** @var InputfieldSelect $item */
        $item = $this->wire()->modules->get('InputfieldSelect');
        $item->name = 'item_uid';
        $item->label = $this->_('Catalog item');
        $item->required = true;
        $itemNames = $this->catalogItemNames();

        $selectedItemUid = $entry?->itemUid ?? $prefillItemUid;

        if ($selectedItemUid !== null && !isset($itemNames[$selectedItemUid])) {
            $assignedItem = $this->catalogItemRepository()->find($selectedItemUid);

            if (
                $assignedItem !== null
                && hash_equals($assignedItem->organizationId, $this->organizationUid())
            ) {
                $itemNames[$selectedItemUid] = $this->catalogItemTitle($assignedItem) . ' · archived';
            }
        }

        foreach ($itemNames as $uid => $name) {
            $item->addOption($uid, $name);
        }

        $item->value = $selectedItemUid ?? '';
        $item->columnWidth = 50;
        $form->add($item);
        $this->addTextField(
            $form,
            'min_quantity',
            $this->_('Minimum quantity'),
            $entry !== null ? $this->quantityFormValue($entry->minQuantity) : '1',
            true,
            25,
        );
        $this->addTextField(
            $form,
            'entry_price',
            $this->_('Price'),
            $this->moneyFormValue($entry?->price),
            true,
            25,
        );
        $this->addSelectField(
            $form,
            'entry_currency',
            $this->_('Currency'),
            [$priceList->currencyCode => $priceList->currencyCode],
            $priceList->currencyCode,
            25,
        );
        $this->addTextField(
            $form,
            'valid_from',
            $this->_('Valid from (YYYY-MM-DD)'),
            $entry?->validFrom?->format('Y-m-d'),
            false,
            37,
        );
        $this->addTextField(
            $form,
            'valid_to',
            $this->_('Valid to (YYYY-MM-DD)'),
            $entry?->validTo?->format('Y-m-d'),
            false,
            38,
        );
        $this->addSubmit($form, $this->_('Save price tier'));

        return $form;
    }

    private function buildOrganizationForm(Organization $organization): InputfieldForm
    {
        /** @var InputfieldForm $form */
        $form = $this->wire()->modules->get('InputfieldForm');
        $form->action = './';
        $form->addClass('InputfieldFormFocusFirst kontor-entity-form kontor-organization-form');
        $this->addTextField($form, 'name', $this->_('Display name'), $organization->name, true, 50);
        $this->addTextField($form, 'legal_name', $this->_('Legal name'), $organization->legalName, false, 50);
        $this->addTextField($form, 'country_code', $this->_('Country code'), $organization->countryCode, true, 33);
        $languageOptions = [
            'en' => 'English',
            'de' => 'Deutsch',
            'fr' => 'Français',
            'es' => 'Español',
            'it' => 'Italiano',
            'nl' => 'Nederlands',
            'pl' => 'Polski',
            'uk' => 'Українська',
        ];

        if (!isset($languageOptions[$organization->defaultLanguage])) {
            $languageOptions[$organization->defaultLanguage] = $organization->defaultLanguage;
        }

        $this->addSelectField(
            $form,
            'default_language',
            $this->_('Default language'),
            $languageOptions,
            $organization->defaultLanguage,
            33
        );
        $this->addTextField(
            $form,
            'default_currency',
            $this->_('Default currency'),
            $organization->defaultCurrency,
            true,
            34
        );
        $timezoneIdentifiers = $this->allowedTimezones();
        $timezones = array_combine($timezoneIdentifiers, $timezoneIdentifiers) ?: [];
        $this->addSelectField(
            $form,
            'timezone',
            $this->_('Timezone'),
            $timezones,
            $organization->timezone,
            50
        );
        $dateFormat = (string) ($organization->settings['dateFormat'] ?? 'Y-m-d');
        $dateFormats = [
            'Y-m-d' => date('Y-m-d'),
            'd.m.Y' => date('d.m.Y'),
            'm/d/Y' => date('m/d/Y'),
            'd/m/Y' => date('d/m/Y'),
        ];

        if (!isset($dateFormats[$dateFormat])) {
            $dateFormats[$dateFormat] = date($dateFormat);
        }

        $this->addSelectField(
            $form,
            'date_format',
            $this->_('Date format'),
            $dateFormats,
            $dateFormat,
            50
        );
        $this->addSubmit($form, $this->_('Save organization'));

        return $form;
    }

    private function saveContactFromForm(InputfieldForm $form, ?Contact $contact): Contact
    {
        if ($contact === null) {
            $contact = Contact::create(
                organizationId: $this->organizationUid(),
                firstName: $this->formValue($form, 'first_name'),
                middleName: null,
                lastName: $this->formValue($form, 'last_name'),
                displayName: $this->requiredFormValue($form, 'display_name'),
            );
        }

        $contact->displayName = $this->requiredFormValue($form, 'display_name');
        $contact->firstName = $this->formValue($form, 'first_name');
        $contact->lastName = $this->formValue($form, 'last_name');
        $contact->email = $this->formValue($form, 'email');
        $contact->phone = $this->formValue($form, 'phone');
        $contact->mobile = $this->formValue($form, 'mobile');
        $contact->jobTitle = $this->formValue($form, 'job_title');
        $contact->status = $this->requiredFormValue($form, 'status');
        $contact->notes = $this->formValue($form, 'notes');
        // LinkedIn lives in the contact's metadata, so no table change; a
        // bare "linkedin.com/in/name" gains its scheme, an empty box clears it.
        $linkedin = trim((string) $this->formValue($form, 'linkedin'));
        if ($linkedin !== '' && !preg_match('~^https?://~i', $linkedin)) {
            $linkedin = 'https://' . $linkedin;
        }
        $metadata = $contact->metadata;
        if ($linkedin === '') {
            unset($metadata['linkedin']);
        } else {
            $metadata['linkedin'] = $linkedin;
        }
        $contact->metadata = $metadata;
        $this->contactRepository()->save($contact);

        return $contact;
    }

    private function saveCompanyFromForm(InputfieldForm $form, ?Company $company): Company
    {
        if ($company === null) {
            $company = Company::create(
                organizationId: $this->organizationUid(),
                legalName: $this->requiredFormValue($form, 'legal_name'),
            );
        }

        $company->legalName = $this->requiredFormValue($form, 'legal_name');
        $company->tradingName = $this->formValue($form, 'trading_name');
        $company->registrationNumber = $this->formValue($form, 'registration_number');
        $company->email = $this->formValue($form, 'email');
        $company->phone = $this->formValue($form, 'phone');
        $company->website = $this->formValue($form, 'website');
        $company->vatNumber = $this->formValue($form, 'vat_number');
        $company->status = $this->requiredFormValue($form, 'status');
        $company->notes = $this->formValue($form, 'notes');
        $this->companyRepository()->save($company);

        return $company;
    }

    private function validateCatalogItemForm(InputfieldForm $form, ?CatalogItem $item): void
    {
        foreach (['sales', 'purchase', 'cost'] as $prefix) {
            $value = $this->formValue($form, $prefix . '_price');

            if ($value !== null && preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value) !== 1) {
                $form->getChildByName($prefix . '_price')?->error(
                    $this->_('Use a number with no more than two decimal places.')
                );
            }
        }

        $sku = $this->formValue($form, 'sku');

        if ($sku !== null) {
            $existing = $this->catalogItemRepository()->findBySku($this->organizationUid(), $sku);

            if ($existing !== null && $existing->uid->toString() !== $item?->uid->toString()) {
                $form->getChildByName('sku')?->error(
                    $this->_('This SKU is already used by another catalog item.')
                );
            }
        }

        $categoryUid = $this->formValue($form, 'category_uid');

        if ($categoryUid !== null) {
            $category = $this->categoryRepository()->find($categoryUid);

            if ($category === null || !hash_equals($category->organizationId, $this->organizationUid())) {
                $form->getChildByName('category_uid')?->error(
                    $this->_('Choose a category from this organization.')
                );
            }
        }
    }

    private function saveCatalogItemFromForm(InputfieldForm $form, ?CatalogItem $item): CatalogItem
    {
        $titles = $item?->title ?? [];
        $descriptions = $item?->description ?? [];

        foreach ($this->catalogFormLanguages() as $locale => $label) {
            $title = $this->formValue($form, 'title_' . $locale);
            $description = $this->formValue($form, 'description_' . $locale);

            if ($title === null) {
                unset($titles[$locale]);
            } else {
                $titles[$locale] = $title;
            }

            if ($description === null) {
                unset($descriptions[$locale]);
            } else {
                $descriptions[$locale] = $description;
            }
        }

        if ($item === null) {
            $item = CatalogItem::create(
                organizationId: $this->organizationUid(),
                title: $titles,
                description: $descriptions,
            );
        }

        $item->title = $titles;
        $item->description = $descriptions;
        $item->itemType = $this->requiredFormValue($form, 'item_type');
        $item->status = $this->requiredFormValue($form, 'status');
        $item->sku = $this->formValue($form, 'sku');
        $item->barcode = $this->formValue($form, 'barcode');
        $item->unitCode = $this->requiredFormValue($form, 'unit_code');
        $item->taxCode = $this->formValue($form, 'tax_code');
        $item->categoryUid = $this->formValue($form, 'category_uid');
        $item->salesPrice = $this->moneyFromForm($form, 'sales');
        $item->purchasePrice = $this->moneyFromForm($form, 'purchase');
        $item->costPrice = $this->moneyFromForm($form, 'cost');
        $item->trackInventory = (bool) $form->getChildByName('track_inventory')?->value;
        $this->catalogItemRepository()->save($item);

        return $item;
    }

    private function validateCatalogCategoryForm(InputfieldForm $form, ?Category $category): void
    {
        $sortOrder = $this->requiredFormValue($form, 'sort_order');

        if (preg_match('/^-?\d+$/', $sortOrder) !== 1) {
            $form->getChildByName('sort_order')?->error($this->_('Sort order must be a whole number.'));
        }

        $parentUid = $this->formValue($form, 'parent_uid');

        if ($parentUid === null) {
            return;
        }

        $parent = $this->categoryRepository()->find($parentUid);

        if ($parent === null || !hash_equals($parent->organizationId, $this->organizationUid())) {
            $form->getChildByName('parent_uid')?->error($this->_('Choose a category from this organization.'));

            return;
        }

        $visited = [];

        while ($parent !== null) {
            $uid = $parent->uid->toString();

            if ($uid === $category?->uid->toString()) {
                $form->getChildByName('parent_uid')?->error(
                    $this->_('A category cannot be placed inside one of its descendants.')
                );

                return;
            }

            if (isset($visited[$uid])) {
                $form->getChildByName('parent_uid')?->error(
                    $this->_('The selected category hierarchy already contains a cycle.')
                );

                return;
            }

            $visited[$uid] = true;
            $parent = $parent->parentUid !== null
                ? $this->categoryRepository()->find($parent->parentUid)
                : null;
        }
    }

    private function saveCatalogCategoryFromForm(InputfieldForm $form, ?Category $category): Category
    {
        $names = $category?->name ?? [];

        foreach ($this->catalogFormLanguages() as $locale => $label) {
            $name = $this->formValue($form, 'name_' . $locale);

            if ($name === null) {
                unset($names[$locale]);
            } else {
                $names[$locale] = $name;
            }
        }

        if ($category === null) {
            $category = Category::create(
                $this->organizationUid(),
                $names,
            );
        }

        $category->name = $names;
        $category->parentUid = $this->formValue($form, 'parent_uid');
        $category->sortOrder = (int) $this->requiredFormValue($form, 'sort_order');
        $category->status = $this->requiredFormValue($form, 'status');
        $this->categoryRepository()->save($category);

        return $category;
    }

    private function validateCatalogPriceListForm(
        InputfieldForm $form,
        ?PriceList $priceList,
    ): void
    {
        $this->validateDateRangeForm($form);

        if (
            $priceList !== null
            && $priceList->currencyCode !== strtoupper($this->requiredFormValue($form, 'currency_code'))
            && $this->priceRepository()->forPriceList($priceList->uid->toString()) !== []
        ) {
            $form->getChildByName('currency_code')?->error(
                $this->_('Remove existing price tiers before changing the currency.')
            );
        }
    }

    private function saveCatalogPriceListFromForm(
        InputfieldForm $form,
        ?PriceList $priceList,
    ): PriceList {
        if ($priceList === null) {
            $priceList = PriceList::create(
                $this->organizationUid(),
                $this->requiredFormValue($form, 'name'),
                $this->requiredFormValue($form, 'currency_code'),
            );
        }

        $priceList->name = $this->requiredFormValue($form, 'name');
        $priceList->currencyCode = strtoupper($this->requiredFormValue($form, 'currency_code'));
        $priceList->status = $this->requiredFormValue($form, 'status');
        $priceList->validFrom = $this->dateFromForm($form, 'valid_from');
        $priceList->validTo = $this->dateFromForm($form, 'valid_to');
        $this->priceListRepository()->save($priceList);

        return $priceList;
    }

    private function validateCatalogPriceEntryForm(InputfieldForm $form): void
    {
        $itemUid = $this->requiredFormValue($form, 'item_uid');
        $item = $this->catalogItemRepository()->find($itemUid);

        if ($item === null || !hash_equals($item->organizationId, $this->organizationUid())) {
            $form->getChildByName('item_uid')?->error(
                $this->_('Choose a catalog item from this organization.')
            );
        }

        $quantity = $this->requiredFormValue($form, 'min_quantity');

        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', $quantity) !== 1 || (float) $quantity <= 0) {
            $form->getChildByName('min_quantity')?->error(
                $this->_('Minimum quantity must be greater than zero with at most six decimal places.')
            );
        }

        $price = $this->requiredFormValue($form, 'entry_price');

        if (preg_match('/^-?\d+(?:\.\d{1,2})?$/', $price) !== 1) {
            $form->getChildByName('entry_price')?->error(
                $this->_('Use a number with no more than two decimal places.')
            );
        }

        $this->validateDateRangeForm($form);
    }

    private function saveCatalogPriceEntryFromForm(
        InputfieldForm $form,
        PriceList $priceList,
        ?PriceListEntry $entry,
    ): PriceListEntry {
        $saved = new PriceListEntry(
            priceListUid: $priceList->uid->toString(),
            itemUid: $this->requiredFormValue($form, 'item_uid'),
            price: $this->moneyFromForm($form, 'entry')
                ?? throw new WireException($this->_('Price is required.')),
            minQuantity: (float) $this->requiredFormValue($form, 'min_quantity'),
            validFrom: $this->dateFromForm($form, 'valid_from'),
            validTo: $this->dateFromForm($form, 'valid_to'),
        );

        if ($entry === null) {
            $this->priceRepository()->save($saved);
        } else {
            $this->priceRepository()->replace($entry->itemUid, $entry->minQuantity, $saved);
        }

        return $saved;
    }

    private function validateDateRangeForm(InputfieldForm $form): void
    {
        $from = $this->validatedDateFromForm($form, 'valid_from');
        $to = $this->validatedDateFromForm($form, 'valid_to');

        if ($from !== null && $to !== null && $from > $to) {
            $form->getChildByName('valid_to')?->error(
                $this->_('The end date must be on or after the start date.')
            );
        }
    }

    private function validatedDateFromForm(
        InputfieldForm $form,
        string $field,
    ): ?\DateTimeImmutable {
        $value = $this->formValue($form, $field);

        if ($value === null) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            $form->getChildByName($field)?->error($this->_('Use a valid date in YYYY-MM-DD format.'));

            return null;
        }

        return $date;
    }

    private function dateFromForm(InputfieldForm $form, string $field): ?\DateTimeImmutable
    {
        $value = $this->formValue($form, $field);

        return $value === null ? null : new \DateTimeImmutable($value);
    }

    private function quantityFormValue(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 6, '.', ''), '0'), '.');
    }

    private function moneyFromForm(InputfieldForm $form, string $prefix): ?Money
    {
        $amount = $this->formValue($form, $prefix . '_price');

        if ($amount === null) {
            return null;
        }

        $negative = str_starts_with($amount, '-');
        $unsigned = ltrim($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $minor = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return Money::ofMinor(
            $negative ? -$minor : $minor,
            $this->requiredFormValue($form, $prefix . '_currency'),
        );
    }

    private function moneyFormValue(?Money $money): ?string
    {
        if ($money === null) {
            return null;
        }

        $minor = $money->amountMinor();
        $negative = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return sprintf('%s%d.%02d', $negative, intdiv($minor, 100), $minor % 100);
    }

    private function addTextField(
        InputfieldForm $form,
        string $name,
        string $label,
        ?string $value,
        bool $required,
        int $width
    ): void {
        /** @var InputfieldText $field */
        $field = $this->wire()->modules->get('InputfieldText');
        $field->name = $name;
        $field->label = $label;
        $field->value = $value ?? '';
        $field->required = $required;
        $field->columnWidth = $width;
        $form->add($field);
    }

    private function addEmailField(
        InputfieldForm $form,
        string $name,
        string $label,
        ?string $value,
        int $width
    ): void {
        /** @var InputfieldEmail $field */
        $field = $this->wire()->modules->get('InputfieldEmail');
        $field->name = $name;
        $field->label = $label;
        $field->value = $value ?? '';
        $field->columnWidth = $width;
        $form->add($field);
    }

    private function addStatusField(InputfieldForm $form, string $value): void
    {
        /** @var InputfieldSelect $field */
        $field = $this->wire()->modules->get('InputfieldSelect');
        $field->name = 'status';
        $field->label = $this->_('Status');
        $field->addOptions([
            'active' => $this->_('Active'),
            'inactive' => $this->_('Inactive'),
        ]);
        $field->value = $value;
        $field->columnWidth = 50;
        $form->add($field);
    }

    /**
     * @return string[]
     */
    private function allowedTimezones(): array
    {
        return ['UTC', ...\DateTimeZone::listIdentifiers()];
    }

    /**
     * @param array<string, string> $options
     */
    private function addSelectField(
        InputfieldForm $form,
        string $name,
        string $label,
        array $options,
        string $value,
        int $width
    ): void {
        /** @var InputfieldSelect $field */
        $field = $this->wire()->modules->get('InputfieldSelect');
        $field->name = $name;
        $field->label = $label;
        $field->addOptions($options);
        $field->value = $value;
        $field->required = true;
        $field->columnWidth = $width;
        $form->add($field);
    }

    private function addTextareaField(
        InputfieldForm $form,
        string $name,
        string $label,
        ?string $value,
        int $width = 100,
    ): void {
        /** @var InputfieldTextarea $field */
        $field = $this->wire()->modules->get('InputfieldTextarea');
        $field->name = $name;
        $field->label = $label;
        $field->value = $value ?? '';
        $field->rows = 5;
        $field->columnWidth = $width;
        $form->add($field);
    }

    private function addSubmit(InputfieldForm $form, string $label): void
    {
        /** @var InputfieldSubmit $submit */
        $submit = $this->wire()->modules->get('InputfieldSubmit');
        $submit->name = 'submit_save';
        $submit->value = $label;
        $submit->icon = 'save';
        $form->add($submit);
    }

    private function addDuplicateConfirmation(InputfieldForm $form): void
    {
        /** @var InputfieldCheckbox $field */
        $field = $this->wire()->modules->get('InputfieldCheckbox');
        $field->name = 'confirm_duplicate';
        $field->label = $this->_('Create this contact anyway');
        $field->description = $this->_('I reviewed the possible duplicate contacts shown above.');
        $field->required = true;
        $form->insertBefore($field, $form->getChildByName('submit_save'));
    }

    private function formValue(InputfieldForm $form, string $name): ?string
    {
        $value = trim((string) $form->getChildByName($name)?->value);

        return $value === '' ? null : $value;
    }

    private function requiredFormValue(InputfieldForm $form, string $name): string
    {
        return trim((string) $form->getChildByName($name)?->value);
    }

    private function nullablePostText(string $name): ?string
    {
        $value = trim((string) $this->wire()->input->post($name));

        return $value === '' ? null : $this->wire()->sanitizer->text($value);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function receiveImportFile(): array
    {
        $upload = $_FILES['import_file'] ?? null;

        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new WireException($this->_('Choose a file to preview.'));
        }

        if ((int) ($upload['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new WireException($this->_('Import files are limited to 10 MB.'));
        }

        $originalName = basename((string) ($upload['name'] ?? 'import'));
        $format = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $format = $format === 'ndjson' ? 'jsonl' : $format;
        $this->requireAction($format, ['csv', 'json', 'jsonl', 'xlsx']);
        $temporary = tempnam($this->wire()->config->paths->cache, 'kontor_import_');

        if ($temporary === false) {
            throw new WireException($this->_('Could not create a temporary import file.'));
        }

        $path = $temporary . '.' . $format;
        rename($temporary, $path);

        if (!move_uploaded_file((string) $upload['tmp_name'], $path)) {
            @unlink($path);
            throw new WireException($this->_('Could not store the uploaded file.'));
        }

        return [$path, $format, $originalName];
    }

    private function storeImportPreview(
        string $batchId,
        string $path,
        string $format,
        string $filename,
        string $entityType,
        int $updated
    ): string {
        $previews = $this->wire()->session->get('kontorImportPreviews');
        $previews = is_array($previews) ? $previews : [];
        $previews[$batchId] = [
            'path' => $path,
            'format' => $format,
            'filename' => $filename,
            'entityType' => $entityType,
            'updated' => $updated,
            'checksum' => hash_file('sha256', $path),
            'expires' => time() + 3600,
        ];
        $this->wire()->session->set('kontorImportPreviews', $previews);

        return $batchId;
    }

    /**
     * @return array{0: ImportBatchResult, 1: string, 2: string}
     */
    private function commitImportPreview(string $token): array
    {
        $previews = $this->wire()->session->get('kontorImportPreviews');
        $preview = is_array($previews) ? ($previews[$token] ?? null) : null;

        if (!is_array($preview) || (int) ($preview['expires'] ?? 0) < time()) {
            throw new WireException($this->_('This import preview has expired. Upload the file again.'));
        }

        $path = (string) ($preview['path'] ?? '');

        if (
            !$this->isManagedImportPath($path)
            || !is_file($path)
            || !hash_equals((string) ($preview['checksum'] ?? ''), (string) hash_file('sha256', $path))
        ) {
            $this->removeImportPreview($token);
            throw new WireException($this->_('The preview file is no longer available or has changed.'));
        }

        $entityType = (string) $preview['entityType'];
        $format = (string) $preview['format'];

        if ((int) $preview['updated'] > 0) {
            $this->requirePermission('kontor-import-update');
        }

        $backupComponent = $entityType === 'catalog_item' ? 'catalog' : 'contacts';
        $backupLabel = $backupComponent === 'catalog' ? 'Catalog' : 'Contacts';
        $backup = $this->backupManager()->create(
            component: $backupComponent,
            kind: 'snapshot',
            organizationId: $this->organizationUid(),
            reason: "Before import {$token}",
        );

        if (!$backup->verified) {
            throw new WireException(sprintf(
                $this->_('The pre-import %s backup could not be verified.'),
                $backupLabel
            ));
        }

        try {
            $result = $this->importManager()->run(
                entityType: $entityType,
                reader: (new FormatResolver())->reader($format),
                path: $path,
                context: new ImportContext(
                    organizationId: $this->organizationUid(),
                    batchId: $token,
                    dryRun: false,
                    actorType: 'user',
                    actorId: (string) $this->wire()->user->id,
                ),
                backupVerification: new BackupVerification(
                    verified: true,
                    checksum: $backup->checksum,
                ),
                auditOrganizationId: $this->organizationInternalId(),
            );

            if ($result->failed > 0) {
                throw new WireException($this->_('The live import reported failed rows.'));
            }
        } catch (\Throwable $exception) {
            $restore = $this->backupManager()->restore(
                $backup->path,
                $backupComponent,
                $this->organizationUid()
            );

            if (!$restore->success) {
                throw new WireException(
                    $this->_('Import failed and automatic restore also failed: ') . implode('; ', $restore->errors),
                    previous: $exception
                );
            }

            $this->markImportAuditRestored($token);
            throw new WireException(
                sprintf(
                    $this->_('Import failed; %s data was restored from the verified backup.'),
                    $backupLabel
                ),
                previous: $exception
            );
        } finally {
            $this->removeImportPreview($token);
        }

        return [$result, (string) $preview['filename'], $backup->id];
    }

    private function cleanupImportPreviews(): void
    {
        $previews = $this->wire()->session->get('kontorImportPreviews');

        if (!is_array($previews)) {
            return;
        }

        foreach ($previews as $token => $preview) {
            if (!is_array($preview) || (int) ($preview['expires'] ?? 0) < time()) {
                $this->removeImportPreview((string) $token);
            }
        }
    }

    private function removeImportPreview(string $token): void
    {
        $previews = $this->wire()->session->get('kontorImportPreviews');
        $previews = is_array($previews) ? $previews : [];
        $preview = $previews[$token] ?? null;

        if (is_array($preview) && $this->isManagedImportPath((string) ($preview['path'] ?? ''))) {
            @unlink((string) $preview['path']);
        }

        unset($previews[$token]);
        $this->wire()->session->set('kontorImportPreviews', $previews);
    }

    private function isManagedImportPath(string $path): bool
    {
        return $path !== ''
            && dirname($path) === rtrim($this->wire()->config->paths->cache, '/\\')
            && str_starts_with(basename($path), 'kontor_import_');
    }

    private function markImportAuditRestored(string $batchId): void
    {
        $statement = \Kontor\Core\Infrastructure\Database\TranslatingPDO::wrap($this->wire()->database)->prepare(
            "UPDATE kontor_audit_events
             SET action = 'import.restored'
             WHERE correlation_id = :batch_id
               AND action IN ('import.created', 'import.updated')"
        );
        $statement->execute(['batch_id' => $batchId]);
    }
}

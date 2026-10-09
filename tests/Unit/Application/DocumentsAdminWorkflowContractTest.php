<?php

declare(strict_types=1);

namespace Kontor\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;

final class DocumentsAdminWorkflowContractTest extends TestCase
{
    public function test_contract_and_letter_previews_use_discovered_fields_and_optional_company_binding(): void
    {
        $root = dirname(__DIR__, 3);
        $controller = (string) file_get_contents($root . '/src/ProcessKontor/Traits/ProcessKontorContentTrait.php');
        $view = (string) file_get_contents($root . '/templates/admin/documents.php');

        self::assertStringContainsString("['letter', 'contract']", $controller);
        self::assertStringContainsString('renderService()->fields($selected)', $controller);
        self::assertStringContainsString("entityType: \$company !== null ? 'company' : 'document_template'", $controller);
        self::assertStringContainsString('DocumentFileName::forCompany(', $controller);
        self::assertStringContainsString('name="field_key[]"', $view);
        self::assertStringContainsString('name="field_value[]"', $view);
        self::assertStringContainsString('name="company_uid"', $view);
        self::assertStringContainsString("\$editing ? 'Edit template' : 'New template'", $view);
    }

    public function test_company_page_lists_organization_scoped_company_files(): void
    {
        $root = dirname(__DIR__, 3);
        $controller = (string) file_get_contents($root . '/src/ProcessKontor/Traits/ProcessKontorPlatformTrait.php');
        $view = (string) file_get_contents($root . '/templates/admin/entity-form.php');

        self::assertStringContainsString("->forEntity(\n                'company'", $controller);
        self::assertStringContainsString('$this->organizationUid()', $controller);
        self::assertStringContainsString('Company files', $view);
        self::assertStringContainsString("files/?id=", $view);
    }
}

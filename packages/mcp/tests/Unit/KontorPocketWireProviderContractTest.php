<?php

declare(strict_types=1);

namespace Kontor\MCP\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class KontorPocketWireProviderContractTest extends TestCase
{
    public function test_provider_is_actor_gated_and_read_only(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/KontorMCP.module.php');
        self::assertStringContainsString("'pocketWireProvider' => true", $source);
        self::assertStringContainsString('pocketWireProviderInfo()', $source);
        self::assertStringContainsString('pocketWireManifest(User $actor)', $source);
        self::assertStringContainsString('pocketWireRead(string $resource, string $identifier, array $query, User $actor)', $source);
        self::assertStringContainsString("hasPermission('kontor-access')", $source);
        self::assertStringContainsString('isLoggedin()', $source);
        self::assertStringContainsString('pocketWireCanReadResource(', $source);
        self::assertStringContainsString('pocketWireCanSearchType(', $source);
        self::assertStringContainsString("'kontor-contacts-contact-view'", $source);
        self::assertStringContainsString("'kontor-catalog-item-view'", $source);
        self::assertStringContainsString("default => ''", $source);
        self::assertStringContainsString("'status' => \$ready ? 'native_read_only' : 'unsupported'", $source);
        self::assertStringContainsString("'actions' => []", $source);
        self::assertStringContainsString('mcpKontorRecordsList(', $source);
        self::assertStringContainsString('mcpKontorRecordGet(', $source);
        self::assertStringNotContainsString('pocketWireWrite', $source);
        self::assertStringNotContainsString('mcpKontorRecordCreate($identifier', $source);
    }
}

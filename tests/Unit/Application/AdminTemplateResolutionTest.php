<?php

declare(strict_types=1);

namespace Kontor\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;

final class AdminTemplateResolutionTest extends TestCase
{
    public function testTraitResolvesTemplatesFromTheModuleRoot(): void
    {
        $traitDirectory = dirname(__DIR__, 3) . '/src/ProcessKontor/Traits';
        $templateDirectory = dirname($traitDirectory, 3) . '/templates/admin';

        self::assertDirectoryExists($templateDirectory);
        self::assertFileExists($templateDirectory . '/dashboard.php');

        $source = (string) file_get_contents($traitDirectory . '/ProcessKontorAuditSupportTrait.php');
        self::assertStringContainsString(
            "include dirname(__DIR__, 3) . '/templates/admin/' . \$name . '.php';",
            $source,
        );
        self::assertStringNotContainsString("__DIR__ . '/templates/admin/'", $source);
    }
}

<?php

declare(strict_types=1);

namespace Kontor\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;

final class ModuleDiscoveryLayoutTest extends TestCase
{
    public function testEveryPackageModuleHasAProcessWireDiscoverableRootWrapper(): void
    {
        $root = dirname(__DIR__, 3);
        $packageFiles = glob($root . '/packages/*/*.module.php') ?: [];

        self::assertCount(34, $packageFiles);

        foreach ($packageFiles as $packageFile) {
            $moduleName = basename($packageFile, '.module.php');
            $wrapper = $root . '/' . $moduleName . '/' . $moduleName . '.module.php';

            self::assertFileExists($wrapper, $moduleName . ' must be visible to ProcessWire module discovery.');
            $source = (string) file_get_contents($wrapper);
            $relativePackage = substr($packageFile, strlen($root) + 1);
            self::assertStringContainsString(
                "dirname(__DIR__) . '/{$relativePackage}'",
                $source,
            );
        }
    }

    public function testWrapperDirectoriesUseTheModuleNameRequiredByProcessWire(): void
    {
        $root = dirname(__DIR__, 3);
        $wrappers = glob($root . '/Kontor*/Kontor*.module.php') ?: [];

        self::assertCount(34, $wrappers);
        foreach ($wrappers as $wrapper) {
            self::assertSame(
                basename(dirname($wrapper)),
                basename($wrapper, '.module.php'),
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Kontor\Documents\Tests\Unit\Application;

use Kontor\Documents\Application\DocumentFileName;
use PHPUnit\Framework\TestCase;

final class DocumentFileNameTest extends TestCase
{
    public function test_company_filename_is_safe_stable_and_dated(): void
    {
        self::assertSame(
            'contract.standard-Acme-Co-2026-10-09.pdf',
            DocumentFileName::forCompany(
                'contract.standard',
                '../Acme & Co',
                new \DateTimeImmutable('2026-10-09'),
            ),
        );
    }

    public function test_non_ascii_or_empty_segments_use_safe_fallbacks(): void
    {
        self::assertSame(
            'document-company-2026-10-09.pdf',
            DocumentFileName::forCompany('...', '会社', new \DateTimeImmutable('2026-10-09')),
        );
    }
}

<?php

declare(strict_types=1);

namespace Kontor\Documents\Application;

final class DocumentFileName
{
    public static function forCompany(string $templateKey, string $companyName, \DateTimeInterface $date): string
    {
        $template = self::segment($templateKey, 'document');
        $company = self::segment($companyName, 'company');

        return "{$template}-{$company}-{$date->format('Y-m-d')}.pdf";
    }

    private static function segment(string $value, string $fallback): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($value)) ?? '';
        $value = trim($value, '-.');

        return $value !== '' ? substr($value, 0, 100) : $fallback;
    }
}

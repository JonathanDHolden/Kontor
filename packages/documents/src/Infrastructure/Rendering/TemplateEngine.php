<?php

declare(strict_types=1);

namespace Kontor\Documents\Infrastructure\Rendering;

/**
 * "Document designer v1" (kontor.md Substage 4.2): a deliberately small
 * templating language over plain HTML, not a general-purpose one —
 * {{field}} / {{field.nested}} value interpolation, {{#each list}}...{{/each}}
 * repeating sections (for document lines), and {{#if field}}...{{/if}}
 * conditional sections (kontor.md#26 "conditional sections"). Page breaks
 * and custom styling are just literal CSS the caller supplies (`custom_css`
 * on DocumentTemplate) — this engine doesn't need to know about them.
 *
 * Values are HTML-escaped on output; the raw HTML a template author writes
 * around a placeholder is trusted (it's the template body itself), but data
 * substituted into it never is.
 */
final class TemplateEngine
{
    private const PARTIAL_PATTERN = '/\{\{>\s*([a-zA-Z][a-zA-Z0-9_.-]{1,63})\s*\}\}/';
    private const EACH_PATTERN = '/\{\{#each\s+([a-zA-Z0-9_.]+)\}\}(.*?)\{\{\/each\}\}/s';
    private const IF_PATTERN = '/\{\{#if\s+([a-zA-Z0-9_.]+)\}\}(.*?)\{\{\/if\}\}/s';
    private const VALUE_PATTERN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data, ?callable $partialResolver = null): string
    {
        $template = $this->expandPartials($template, $partialResolver);
        $template = $this->renderEach($template, $data);
        $template = $this->renderIf($template, $data);

        return $this->renderValues($template, $data);
    }

    /**
     * Returns unique top-level form fields in first-use order. Fields inside
     * repeating sections belong to their row data and are deliberately omitted.
     *
     * @return string[]
     */
    public function fields(string $template, ?callable $partialResolver = null): array
    {
        $template = $this->expandPartials($template, $partialResolver);
        $template = (string) preg_replace(self::EACH_PATTERN, '', $template);
        preg_match_all(
            '/\{\{#if\s+([a-zA-Z0-9_.]+)\}\}|\{\{\s*([a-zA-Z][a-zA-Z0-9_.]*)\s*\}\}/',
            $template,
            $matches,
            PREG_SET_ORDER,
        );

        $fields = [];
        foreach ($matches as $match) {
            $field = $match[1] !== '' ? $match[1] : $match[2];
            if (!in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function expandPartials(
        string $template,
        ?callable $resolver,
        int $depth = 0,
        array $stack = [],
    ): string {
        if (!str_contains($template, '{{>')) {
            return $template;
        }
        if ($resolver === null) {
            throw new \RuntimeException('This document uses shared sections, but no section resolver is configured.');
        }

        return (string) preg_replace_callback(self::PARTIAL_PATTERN, function (array $matches) use (
            $resolver,
            $depth,
            $stack,
        ): string {
            $key = $matches[1];
            if (in_array($key, $stack, true)) {
                throw new \RuntimeException('Circular document section include: ' . implode(' -> ', [...$stack, $key]));
            }
            if ($depth >= 3) {
                throw new \RuntimeException('Document section include depth exceeds the limit of 3.');
            }

            $partial = $resolver($key);
            if (!is_string($partial)) {
                throw new \RuntimeException("Document section \"{$key}\" was not found.");
            }

            return $this->expandPartials($partial, $resolver, $depth + 1, [...$stack, $key]);
        }, $template) ?? $template;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderEach(string $template, array $data): string
    {
        return (string) preg_replace_callback(self::EACH_PATTERN, function (array $matches) use ($data): string {
            $items = $this->resolve($matches[1], $data);

            if (!is_array($items)) {
                return '';
            }

            $rendered = '';
            foreach ($items as $item) {
                $itemData = is_array($item) ? array_merge($data, $item) : $data;
                $rendered .= $this->render($matches[2], $itemData);
            }

            return $rendered;
        }, $template) ?? $template;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderIf(string $template, array $data): string
    {
        return (string) preg_replace_callback(self::IF_PATTERN, function (array $matches) use ($data): string {
            $value = $this->resolve($matches[1], $data);

            return $value ? $this->render($matches[2], $data) : '';
        }, $template) ?? $template;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderValues(string $template, array $data): string
    {
        return (string) preg_replace_callback(self::VALUE_PATTERN, function (array $matches) use ($data): string {
            $value = $this->resolve($matches[1], $data);

            if (is_array($value)) {
                return '';
            }

            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }, $template) ?? $template;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolve(string $path, array $data): mixed
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
}

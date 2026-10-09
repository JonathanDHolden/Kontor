<?php

declare(strict_types=1);

namespace Kontor\Documents\Tests\Unit\Infrastructure;

use Kontor\Documents\Infrastructure\Rendering\TemplateEngine;
use PHPUnit\Framework\TestCase;

final class TemplateEngineTest extends TestCase
{
    public function test_substitutes_a_simple_placeholder(): void
    {
        $html = (new TemplateEngine())->render('<p>Hello {{name}}</p>', ['name' => 'Widget Corp']);

        $this->assertSame('<p>Hello Widget Corp</p>', $html);
    }

    public function test_substitutes_a_nested_dot_path(): void
    {
        $html = (new TemplateEngine())->render('<p>{{customer.name}}</p>', ['customer' => ['name' => 'Acme']]);

        $this->assertSame('<p>Acme</p>', $html);
    }

    public function test_html_escapes_substituted_values(): void
    {
        $html = (new TemplateEngine())->render('<p>{{name}}</p>', ['name' => '<script>alert(1)</script>']);

        $this->assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $html);
    }

    public function test_missing_placeholder_renders_as_empty_string(): void
    {
        $html = (new TemplateEngine())->render('<p>[{{missing}}]</p>', []);

        $this->assertSame('<p>[]</p>', $html);
    }

    public function test_each_repeats_a_section_per_item(): void
    {
        $template = '<ul>{{#each lines}}<li>{{title}} x{{quantity}}</li>{{/each}}</ul>';
        $data = ['lines' => [
            ['title' => 'Widget', 'quantity' => 2],
            ['title' => 'Gadget', 'quantity' => 1],
        ]];

        $html = (new TemplateEngine())->render($template, $data);

        $this->assertSame('<ul><li>Widget x2</li><li>Gadget x1</li></ul>', $html);
    }

    public function test_each_over_an_empty_list_renders_nothing(): void
    {
        $html = (new TemplateEngine())->render('<ul>{{#each lines}}<li>{{title}}</li>{{/each}}</ul>', ['lines' => []]);

        $this->assertSame('<ul></ul>', $html);
    }

    public function test_if_renders_the_section_when_truthy(): void
    {
        $html = (new TemplateEngine())->render('{{#if discount}}<p>Discount applied</p>{{/if}}', ['discount' => true]);

        $this->assertSame('<p>Discount applied</p>', $html);
    }

    public function test_if_omits_the_section_when_falsy_or_missing(): void
    {
        $engine = new TemplateEngine();

        $this->assertSame('', $engine->render('{{#if discount}}<p>Discount applied</p>{{/if}}', ['discount' => false]));
        $this->assertSame('', $engine->render('{{#if discount}}<p>Discount applied</p>{{/if}}', []));
    }

    public function test_expands_nested_shared_sections_before_rendering(): void
    {
        $sections = [
            'contract.opening' => '<p>Between us and {{client_name}}</p>{{> contract.signature}}',
            'contract.signature' => '<p>Signed by {{signatory}}</p>',
        ];
        $resolver = static fn (string $key): ?string => $sections[$key] ?? null;

        $html = (new TemplateEngine())->render(
            '{{> contract.opening}}<p>{{service_details}}</p>',
            ['client_name' => 'Acme', 'signatory' => 'A. Person', 'service_details' => 'Support'],
            $resolver,
        );

        $this->assertSame(
            '<p>Between us and Acme</p><p>Signed by A. Person</p><p>Support</p>',
            $html,
        );
    }

    public function test_rejects_cycles_missing_sections_and_excessive_depth(): void
    {
        $engine = new TemplateEngine();
        $cycle = null;
        try {
            $engine->render('{{> aa}}', [], static fn (string $key): ?string => $key === 'aa' ? '{{> aa}}' : null);
        } catch (\RuntimeException $exception) {
            $cycle = $exception;
        }
        $this->assertInstanceOf(\RuntimeException::class, $cycle);
        $this->assertStringContainsString('Circular', $cycle->getMessage());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('depth exceeds');
        $engine->render('{{> aa}}', [], static fn (string $key): ?string => [
            'aa' => '{{> bb}}',
            'bb' => '{{> cc}}',
            'cc' => '{{> dd}}',
            'dd' => 'too deep',
        ][$key] ?? null);
    }

    public function test_missing_shared_section_fails_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('was not found');

        (new TemplateEngine())->render('{{> missing}}', [], static fn (): ?string => null);
    }

    public function test_fields_preserve_order_expand_sections_and_skip_each_rows(): void
    {
        $fields = (new TemplateEngine())->fields(
            '{{title}}{{> shared}}{{#each lines}}{{description}}{{amount}}{{/each}}{{title}}',
            static fn (string $key): ?string => $key === 'shared'
                ? '{{#if client_name}}{{client_name}}{{/if}}{{service_details}}'
                : null,
        );

        $this->assertSame(['title', 'client_name', 'service_details'], $fields);
    }
}

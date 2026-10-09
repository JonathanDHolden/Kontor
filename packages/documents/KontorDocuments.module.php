<?php

namespace ProcessWire;

use Kontor\Core\Infrastructure\Migrations\MigrationRunner;
use Kontor\Core\Infrastructure\Persistence\OrganizationRepository;
use Kontor\Core\Infrastructure\Registry\ComponentRegistry;
use Kontor\Core\Infrastructure\Registry\TranslationRegistry;
use Kontor\Documents\Application\DocumentRenderService;
use Kontor\Documents\Application\DocumentSnapshotBuilder;
use Kontor\Documents\Application\TemplateManager;
use Kontor\Documents\Health\DocumentsHealthCheck;
use Kontor\Documents\Infrastructure\Persistence\TemplateRepository;
use Kontor\Documents\Infrastructure\Rendering\PdfRenderer;
use Kontor\Documents\Infrastructure\Rendering\TemplateEngine;
use Kontor\Documents\Migrations\Migration0001CreateDocumentTemplatesTable;

/**
 * KontorDocuments bootstrap module (kontor.md Substage 4.2). No
 * import/export/search milestone this substage, same as Sales — Documents
 * only registers its own translations and provides its services directly.
 * The shared admin wires DocumentSnapshotBuilder into quotation issuance;
 * the package itself remains independent and exposes the rendering services.
 */
class KontorDocuments extends WireData implements Module
{
    public static function getModuleInfo(): array
    {
        return [
            'title' => 'Kontor Documents',
            'summary' => 'Document templates, HTML/PDF rendering, multilingual output, immutable issued-document snapshots.',
            'version' => '110',
            'author' => 'Maxim Semenov',
            'href' => 'https://github.com/mxmsmnv/KontorDocuments',
            'icon' => 'file-pdf-o',
            'singular' => true,
            'autoload' => true,
            'requires' => ['Kontor', 'KontorFiles'],
            'permissions' => [
                'kontor-documents-template-view' => 'View document templates',
                'kontor-documents-template-create' => 'Create document templates',
                'kontor-documents-template-edit' => 'Edit document templates',
                'kontor-documents-template-archive' => 'Archive document templates',
                'kontor-documents-render' => 'Render documents to HTML/PDF',
            ],
        ];
    }

    private ?TemplateRepository $templateRepository = null;
    private ?TemplateEngine $engine = null;
    private ?PdfRenderer $pdf = null;

    public function init(): void
    {
        /** @var Kontor $kontor */
        $kontor = $this->wire()->modules->get('Kontor');

        $this->registerTranslations($kontor->container()->get(TranslationRegistry::class));
    }

    private function registerTranslations(TranslationRegistry $translations): void
    {
        foreach (['en', 'fr', 'de', 'es'] as $language) {
            $path = __DIR__ . "/resources/translations/{$language}/messages.json";

            if (!is_file($path)) {
                continue;
            }

            $strings = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
            $translations->register('KontorDocuments', $language, $strings);
        }
    }

    public function templateRepository(): TemplateRepository
    {
        return $this->templateRepository ??= new TemplateRepository($this->pdo(), $this->organizations());
    }

    public function templateManager(): TemplateManager
    {
        return new TemplateManager($this->templateRepository());
    }

    public function renderService(): DocumentRenderService
    {
        return new DocumentRenderService(
            $this->templateEngine(),
            $this->pdfRenderer(),
            $this->templateRepository(),
        );
    }

    public function snapshotBuilder(): DocumentSnapshotBuilder
    {
        return new DocumentSnapshotBuilder($this->renderService());
    }

    public function healthCheck(): DocumentsHealthCheck
    {
        return new DocumentsHealthCheck($this->templateEngine(), $this->pdfRenderer());
    }

    private function templateEngine(): TemplateEngine
    {
        return $this->engine ??= new TemplateEngine();
    }

    private function pdfRenderer(): PdfRenderer
    {
        return $this->pdf ??= new PdfRenderer();
    }

    private function organizations(): OrganizationRepository
    {
        /** @var Kontor $kontor */
        $kontor = $this->wire()->modules->get('Kontor');

        return $kontor->container()->get(OrganizationRepository::class);
    }

    private function pdo(): \PDO
    {
        return \Kontor\Core\Infrastructure\Database\TranslatingPDO::wrap($this->wire()->database);
    }

    public function ___install(): void
    {
        $pdo = $this->pdo();

        $runner = new MigrationRunner($pdo);
        $runner->ensureLedgerExists();
        $runner->run([
            new Migration0001CreateDocumentTemplatesTable(),
        ]);

        $components = new ComponentRegistry($pdo);
        $components->markInstalled('documents', self::getModuleInfo()['version'], 'documents');
        $components->enable('documents');
    }

    public function ___upgrade($fromVersion, $toVersion): void
    {
        $components = new ComponentRegistry($this->pdo());
        $components->markInstalled('documents', self::getModuleInfo()['version'], 'documents');
        $components->enable('documents');
    }

    /**
     * Codex rule #10: ordinary uninstall must not remove user data.
     */
    public function ___uninstall(): void
    {
        $this->message($this->_('Kontor Documents module removed. Template data was kept intact.'));
    }
}

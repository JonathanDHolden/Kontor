<?php

namespace ProcessWire;

use Kontor\Core\Application\AuditLogger;
use Kontor\Core\Application\BackupManager;
use Kontor\Core\Application\ComponentManager;
use Kontor\Core\Application\ExportManager;
use Kontor\Core\Application\HealthCheckRunner;
use Kontor\Core\Application\ImportManager;
use Kontor\Core\Health\CoreHealthCheck;
use Kontor\Core\Infrastructure\Backup\CoreBackupProvider;
use Kontor\Core\Infrastructure\Backup\BackupArchiveBuilder;
use Kontor\Core\Infrastructure\Database\TranslatingPDO;
use Kontor\Core\Infrastructure\Events\EventDispatcher;
use Kontor\Core\Infrastructure\Migrations\MigrationRunner;
use Kontor\Core\Infrastructure\Persistence\ExtensionRepository;
use Kontor\Core\Infrastructure\Persistence\AuditEventRepository;
use Kontor\Core\Infrastructure\Persistence\OrganizationRepository;
use Kontor\Core\Infrastructure\Persistence\RelationRepository;
use Kontor\Core\Infrastructure\Persistence\SequenceService;
use Kontor\Core\Infrastructure\Recovery\RecoveryModeManager;
use Kontor\Core\Infrastructure\Registry\BackupProviderRegistry;
use Kontor\Core\Infrastructure\Registry\CapabilityRegistry;
use Kontor\Core\Infrastructure\Registry\ComponentRegistry;
use Kontor\Core\Infrastructure\Registry\ExportProviderRegistry;
use Kontor\Core\Infrastructure\Registry\ImportProviderRegistry;
use Kontor\Core\Infrastructure\Registry\RepositoryRegistry;
use Kontor\Core\Infrastructure\Registry\ReportProviderRegistry;
use Kontor\Core\Infrastructure\Registry\RouteRegistry;
use Kontor\Core\Infrastructure\Registry\TranslationRegistry;
use Kontor\Core\Migrations\Migration0001CreateOrganizationsTable;
use Kontor\Core\Migrations\Migration0002CreateComponentsTable;
use Kontor\Core\Migrations\Migration0003CreateAuditEventsTable;
use Kontor\Core\Migrations\Migration0004CreateSequencesTable;
use Kontor\Core\Migrations\Migration0005CreateExtensionsTable;
use Kontor\Core\Migrations\Migration0006CreateRelationsTable;
use Kontor\Core\Support\Container;

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Kontor Core bootstrap module (kontor.md Substage 1.1). Boots the service
 * container and the core registries; business components depend on this
 * module and register themselves into it, they never get their own
 * standalone bootstrap.
 */
class Kontor extends WireData implements Module
{
    public static function getModuleInfo(): array
    {
        return [
            'title' => 'Kontor',
            'summary' => 'Open-source modular ERP, CRM and business operations platform.',
            'version' => '111',
            'author' => 'Maxim Semenov',
            'href' => 'https://github.com/mxmsmnv/Kontor',
            'icon' => 'cubes',
            'singular' => true,
            'autoload' => true,
            'requires' => ['ProcessWire>=3.0.240'],
            'installs' => ['ProcessKontor'],
            'permissions' => [
                'kontor-access' => 'Access the Kontor admin',
                'kontor-admin' => 'Administer Kontor settings and components',
                'kontor-components-view' => 'View installed Kontor components',
                'kontor-components-install' => 'Install Kontor components',
                'kontor-components-update' => 'Update Kontor components',
                'kontor-components-disable' => 'Disable Kontor components',
                'kontor-components-remove' => 'Remove Kontor components',
                'kontor-audit-view' => 'View the Kontor audit log',
                'kontor-health-view' => 'View Kontor health checks',
                'kontor-backups-view' => 'View Kontor backups',
                'kontor-backups-create' => 'Create Kontor backups',
                'kontor-backups-download' => 'Download Kontor backups',
                'kontor-backups-restore' => 'Restore Kontor backups',
                'kontor-backups-configure' => 'Configure Kontor backup destinations',
                'kontor-updates-bypass-backup' => 'Update components without a verified pre-update backup',
                'kontor-import' => 'Import records',
                'kontor-import-update' => 'Import records that update existing records',
                'kontor-import-admin' => 'Roll back import batches',
                'kontor-export' => 'Export records',
                'kontor-export-personal-data' => 'Export personal data fields',
                'kontor-export-financial' => 'Export financial data fields',
            ],
        ];
    }

    private ?Container $container = null;

    public function init(): void
    {
        $this->container();
    }

    /**
     * The shared service container. Business components receive the
     * services they need via ComponentContext rather than pulling this
     * directly (kontor.md#9.1), but the container itself lives here.
     */
    public function container(): Container
    {
        if ($this->container !== null) {
            return $this->container;
        }

        $container = new Container();
        $pdo = $this->pdo();

        $container->instance(\PDO::class, $pdo);
        $container->bind(CapabilityRegistry::class, static fn (): CapabilityRegistry => new CapabilityRegistry());
        $container->bind(EventDispatcher::class, static fn (): EventDispatcher => new EventDispatcher());
        $container->bind(RouteRegistry::class, static fn (): RouteRegistry => new RouteRegistry());
        $container->bind(TranslationRegistry::class, static fn (): TranslationRegistry => new TranslationRegistry());
        $container->bind(ComponentRegistry::class, static fn (): ComponentRegistry => new ComponentRegistry($pdo));
        $container->bind(OrganizationRepository::class, static fn (): OrganizationRepository => new OrganizationRepository($pdo));
        $container->bind(ExtensionRepository::class, static fn (): ExtensionRepository => new ExtensionRepository($pdo));
        $container->bind(AuditEventRepository::class, static fn (): AuditEventRepository => new AuditEventRepository($pdo));
        $container->bind(SequenceService::class, static fn (Container $c): SequenceService => new SequenceService($pdo, $c->get(OrganizationRepository::class)));
        $container->bind(RelationRepository::class, static fn (Container $c): RelationRepository => new RelationRepository($pdo, $c->get(OrganizationRepository::class)));
        $container->bind(AuditLogger::class, static fn (): AuditLogger => new AuditLogger($pdo));
        $container->bind(BackupArchiveBuilder::class, static fn (): BackupArchiveBuilder => new BackupArchiveBuilder());
        $container->bind(HealthCheckRunner::class, static fn (): HealthCheckRunner => new HealthCheckRunner());
        $container->bind(
            CoreHealthCheck::class,
            fn (): CoreHealthCheck => new CoreHealthCheck(
                $pdo,
                $this->wire()->config->paths->assets . 'kontor/backups'
            )
        );
        $container->bind(ComponentManager::class, static function (Container $c): ComponentManager {
            $organizations = $c->get(OrganizationRepository::class);
            $organization = $organizations->defaultOrganization('US', 'en', 'EUR');

            return new ComponentManager(
                registry: $c->get(ComponentRegistry::class),
                events: $c->get(EventDispatcher::class),
                audit: $c->get(AuditLogger::class),
                organizationId: $organizations->internalIdOf($organization->uid->toString()),
            );
        });
        $container->bind(BackupProviderRegistry::class, static function () use ($pdo): BackupProviderRegistry {
            $registry = new BackupProviderRegistry();
            $registry->register('core', new CoreBackupProvider($pdo));

            return $registry;
        });
        $container->bind(
            RecoveryModeManager::class,
            fn (): RecoveryModeManager => new RecoveryModeManager($this->wire()->config->paths->assets . 'kontor/state')
        );
        $container->bind(BackupManager::class, fn (Container $c): BackupManager => new BackupManager(
            $c->get(BackupProviderRegistry::class),
            $this->wire()->config->paths->assets . 'kontor/backups'
        ));
        $container->bind(ImportProviderRegistry::class, static fn (): ImportProviderRegistry => new ImportProviderRegistry());
        $container->bind(ExportProviderRegistry::class, static fn (): ExportProviderRegistry => new ExportProviderRegistry());
        $container->bind(RepositoryRegistry::class, static fn (): RepositoryRegistry => new RepositoryRegistry());
        $container->bind(ReportProviderRegistry::class, static fn (): ReportProviderRegistry => new ReportProviderRegistry());
        $container->bind(ImportManager::class, static fn (Container $c): ImportManager => new ImportManager(
            providers: $c->get(ImportProviderRegistry::class),
            repositories: $c->get(RepositoryRegistry::class),
            audit: $c->get(AuditLogger::class),
            events: $c->get(EventDispatcher::class),
            pdo: $pdo,
        ));
        $container->bind(
            ExportManager::class,
            static fn (Container $c): ExportManager => new ExportManager($c->get(ExportProviderRegistry::class))
        );

        return $this->container = $container;
    }

    /** Keep standalone PDO contracts while using ProcessWire's SQL translator. */
    private function pdo(): \PDO
    {
        return TranslatingPDO::wrap($this->wire()->database);
    }

    public function ___install(): void
    {
        $pdo = $this->pdo();

        $runner = new MigrationRunner($pdo);
        $runner->ensureLedgerExists();
        $runner->run([
            new Migration0001CreateOrganizationsTable(),
            new Migration0002CreateComponentsTable(),
            new Migration0003CreateAuditEventsTable(),
            new Migration0004CreateSequencesTable(),
            new Migration0005CreateExtensionsTable(),
            new Migration0006CreateRelationsTable(),
        ]);

        (new OrganizationRepository($pdo))->defaultOrganization('US', 'en', 'EUR');

        $components = new ComponentRegistry($pdo);
        $components->markInstalled('core', self::getModuleInfo()['version'], 'core');
        $components->enable('core');
    }

    public function ___upgrade($fromVersion, $toVersion): void
    {
        $components = new ComponentRegistry($this->pdo());
        $components->markInstalled('core', self::getModuleInfo()['version'], 'core');
        $components->enable('core');
    }

    /**
     * Codex rule #10: ordinary uninstall must not remove user data, so the
     * business tables created by ___install() are left in place.
     */
    public function ___uninstall(): void
    {
        $this->message($this->_('Kontor Core module removed. Business data tables were kept intact.'));
    }
}

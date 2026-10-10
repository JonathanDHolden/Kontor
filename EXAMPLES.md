# Kontor Integration Examples

These examples target Kontor 1.1.1 and must be checked against the installed version and live site configuration.

## Render a Document with Shared Sections

```php
<?php namespace ProcessWire;

if ($modules->isInstalled('KontorDocuments')) {
    /** @var KontorDocuments $documents */
    $documents = $modules->get('KontorDocuments');
    $template = $documents->templateRepository()->findCurrentVersion(
        $organizationUid,
        'contract.standard',
        'en',
    );
    if ($template !== null) {
        $requiredFields = $documents->renderService()->fields($template);
        $html = $documents->renderService()->renderPreviewHtml($template, $validatedData);
    }
}
```

Template authors may reuse a same-organization section with `{{> contract.terms}}`. Validate every field value and organization identifier before rendering; storing or delivering the output is a separate authorized action.

## Resolve an Optional Component

```php
<?php namespace ProcessWire;

if (!$modules->isInstalled('KontorContacts')) {
    return;
}

/** @var KontorContacts $contacts */
$contacts = $modules->get('KontorContacts');
$repository = $contacts->contactRepository();
```

Keep organization, permission and ownership checks at the calling boundary. Use domain DTOs/value objects required by the verified repository signature; never guess an input array shape.

## Federated Search

```php
<?php namespace ProcessWire;

if ($modules->isInstalled('KontorSearch')) {
    /** @var KontorSearch $search */
    $search = $modules->get('KontorSearch');
    $service = $search->globalSearchService();
    // Build the installed version's SearchQuery DTO, then call the service.
}
```

The registry searches only installed providers. Bound query length, result limit and entity types at the request boundary.

## Queue Work

```php
<?php namespace ProcessWire;

if ($modules->isInstalled('KontorQueue')) {
    /** @var KontorQueue $queueModule */
    $queueModule = $modules->get('KontorQueue');
    $queue = $queueModule->queue();
    // Enqueue only a job name registered in jobRegistry(), with a bounded payload.
}
```

Workers are separate processes. Make handlers idempotent, bound retries and use local fakes for external services.

## Preview Settings Before Apply

```php
<?php namespace ProcessWire;

if ($modules->isInstalled('KontorSettings')) {
    /** @var KontorSettings $settings */
    $settings = $modules->get('KontorSettings');
    $migration = $settings->migrationService();
    // Export or preview with the exact installed signature before any approved apply.
}
```

Portable profiles exclude secrets. Applying a profile is a configuration mutation and requires approval and rollback planning.

## Subscribe to a Verified Business Event

```php
<?php namespace ProcessWire;

use Kontor\Core\Infrastructure\Events\EventDispatcher;

/** @var Kontor $kontor */
$kontor = $modules->get('Kontor');
$events = $kontor->container()->get(EventDispatcher::class);
$events->subscribe('verified.event.name', function ($event): void {
    // Validate the installed component's documented event payload.
});
```

Replace `verified.event.name` only with an event declared by the installed component metadata. Never derive contracts from an admin label or audit message.

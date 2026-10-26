<?php

declare(strict_types=1);

namespace Kontor\Contacts\Infrastructure\Persistence;

use Kontor\Contacts\Domain\Contact;
use Kontor\Core\Infrastructure\Persistence\OrganizationRepository;
use Kontor\SDK\Contracts\RepositoryInterface;
use Kontor\SDK\ValueObjects\Uid;
use InvalidArgumentException;
use RuntimeException;

/**
 * kontor.md#12.1. Implements the SDK's generic RepositoryInterface (rather
 * than only exposing naturally-typed methods) so it can be registered into
 * ImportManager's RepositoryRegistry for batch rollback (Substage 1.5) —
 * save()/find()/require() accept/return `object` per that contract, with
 * find()/require() covariantly narrowed to ?Contact/Contact since PHP
 * allows narrowing return types but not parameter types.
 */
final class ContactRepository implements RepositoryInterface
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    public function find(string $id): ?Contact
    {
        $statement = $this->pdo->prepare('SELECT * FROM kontor_contacts WHERE uid = :uid AND deleted_at IS NULL');
        $statement->execute(['uid' => $id]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function require(string $id): Contact
    {
        return $this->find($id) ?? throw new RuntimeException("Contact \"{$id}\" was not found.");
    }

    public function save(object $entity): void
    {
        if (!$entity instanceof Contact) {
            throw new InvalidArgumentException('ContactRepository::save() expects a Contact.');
        }

        $organizationId = $this->organizations->internalIdOf($entity->organizationId);
        $now = $this->now();

        $statement = $this->pdo->prepare(
            'INSERT INTO kontor_contacts
                (uid, organization_id, type, first_name, middle_name, last_name, display_name, email, phone,
                 mobile, job_title, preferred_language, preferred_currency, source, status, assigned_user_id,
                 notes, metadata_json, created_at, updated_at, version)
             VALUES
                (:uid, :organization_id, :type, :first_name, :middle_name, :last_name, :display_name, :email, :phone,
                 :mobile, :job_title, :preferred_language, :preferred_currency, :source, :status, :assigned_user_id,
                 :notes, :metadata_json, :created_at, :updated_at, 1)
             ON DUPLICATE KEY UPDATE
                type = VALUES(type), first_name = VALUES(first_name), middle_name = VALUES(middle_name),
                last_name = VALUES(last_name), display_name = VALUES(display_name), email = VALUES(email),
                phone = VALUES(phone), mobile = VALUES(mobile), job_title = VALUES(job_title),
                preferred_language = VALUES(preferred_language), preferred_currency = VALUES(preferred_currency),
                source = VALUES(source), status = VALUES(status), assigned_user_id = VALUES(assigned_user_id),
                notes = VALUES(notes), metadata_json = VALUES(metadata_json), updated_at = VALUES(updated_at),
                version = version + 1'
        );

        $statement->execute([
            'uid' => $entity->uid->toString(),
            'organization_id' => $organizationId,
            'type' => $entity->type,
            'first_name' => $entity->firstName,
            'middle_name' => $entity->middleName,
            'last_name' => $entity->lastName,
            'display_name' => $entity->displayName,
            'email' => $entity->email,
            'phone' => $entity->phone,
            'mobile' => $entity->mobile,
            'job_title' => $entity->jobTitle,
            'preferred_language' => $entity->preferredLanguage,
            'preferred_currency' => $entity->preferredCurrency,
            'source' => $entity->source,
            'status' => $entity->status,
            'assigned_user_id' => $entity->assignedUserId,
            'notes' => $entity->notes,
            'metadata_json' => $entity->metadata !== [] ? json_encode($entity->metadata, JSON_THROW_ON_ERROR) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function archive(string $id): void
    {
        $statement = $this->pdo->prepare('UPDATE kontor_contacts SET archived_at = :now WHERE uid = :uid');
        $statement->execute(['now' => $this->now(), 'uid' => $id]);
    }

    public function restore(string $id): void
    {
        $statement = $this->pdo->prepare('UPDATE kontor_contacts SET archived_at = NULL WHERE uid = :uid');
        $statement->execute(['uid' => $id]);
    }

    /**
     * Distinct from archive(): a legal/GDPR erasure marker
     * (kontor.md section 33), gated by kontor-contacts-contact-delete
     * rather than kontor-contacts-contact-archive.
     */
    public function delete(string $id): void
    {
        $statement = $this->pdo->prepare('UPDATE kontor_contacts SET deleted_at = :now WHERE uid = :uid');
        $statement->execute(['now' => $this->now(), 'uid' => $id]);
    }

    public function findByEmail(string $organizationUid, string $email): ?Contact
    {
        return $this->findByColumn($organizationUid, 'email', $email);
    }

    public function findByPhone(string $organizationUid, string $phone): ?Contact
    {
        return $this->findByColumn($organizationUid, 'phone', $phone);
    }

    /**
     * @return Contact[]
     */
    public function findAll(
        string $organizationUid,
        string $query = '',
        int $limit = 100,
        int $offset = 0,
        string $status = '',
        bool $withLinkedIn = false,
    ): array
    {
        return $this->findList($organizationUid, $query, false, $limit, $offset, $status, $withLinkedIn);
    }

    /**
     * @return Contact[]
     */
    public function findArchived(
        string $organizationUid,
        string $query = '',
        int $limit = 100,
        int $offset = 0,
        string $status = '',
        bool $withLinkedIn = false,
    ): array
    {
        return $this->findList($organizationUid, $query, true, $limit, $offset, $status, $withLinkedIn);
    }

    /**
     * @return Contact[]
     */
    private function findList(
        string $organizationUid,
        string $query,
        bool $archived,
        int $limit,
        int $offset,
        string $status,
        bool $withLinkedIn = false,
    ): array
    {
        $organizationId = $this->organizations->internalIdOf($organizationUid);
        $query = trim($query);
        $limit = max(1, min($limit, 250));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM kontor_contacts
            WHERE organization_id = :organization_id
              AND deleted_at IS NULL
              AND archived_at IS ' . ($archived ? 'NOT NULL' : 'NULL');

        if ($query !== '') {
            $sql .= ' AND (
                display_name LIKE :query
                OR email LIKE :query
                OR phone LIKE :query
                OR mobile LIKE :query
                OR job_title LIKE :query
            )';
        }

        if ($status !== '') {
            $sql .= ' AND status = :status';
        }

        if ($withLinkedIn) {
            // The LinkedIn address lives in metadata (see the contact form), so
            // "has a LinkedIn profile" is a key in the JSON.
            $sql .= ' AND metadata_json LIKE \'%"linkedin":"%\'';
        }

        $sql .= ' ORDER BY updated_at DESC, display_name ASC LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':organization_id', $organizationId, \PDO::PARAM_INT);

        if ($query !== '') {
            $statement->bindValue(':query', '%' . $query . '%');
        }

        if ($status !== '') {
            $statement->bindValue(':status', $status);
        }

        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            fn (array $row): Contact => $this->hydrate($row),
            $statement->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function countMatching(
        string $organizationUid,
        string $query = '',
        bool $archived = false,
        string $status = '',
        bool $withLinkedIn = false,
    ): int
    {
        $organizationId = $this->organizations->internalIdOf($organizationUid);
        $query = trim($query);
        $sql = 'SELECT COUNT(*) FROM kontor_contacts
            WHERE organization_id = :organization_id
              AND deleted_at IS NULL
              AND archived_at IS ' . ($archived ? 'NOT NULL' : 'NULL');

        if ($query !== '') {
            $sql .= ' AND (
                display_name LIKE :query
                OR email LIKE :query
                OR phone LIKE :query
                OR mobile LIKE :query
                OR job_title LIKE :query
            )';
        }

        if ($status !== '') {
            $sql .= ' AND status = :status';
        }

        if ($withLinkedIn) {
            // The LinkedIn address lives in metadata (see the contact form), so
            // "has a LinkedIn profile" is a key in the JSON.
            $sql .= ' AND metadata_json LIKE \'%"linkedin":"%\'';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':organization_id', $organizationId, \PDO::PARAM_INT);

        if ($query !== '') {
            $statement->bindValue(':query', '%' . $query . '%');
        }

        if ($status !== '') {
            $statement->bindValue(':status', $status);
        }

        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function countActive(string $organizationUid): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM kontor_contacts
             WHERE organization_id = :organization_id
               AND deleted_at IS NULL
               AND archived_at IS NULL'
        );
        $statement->execute([
            'organization_id' => $this->organizations->internalIdOf($organizationUid),
        ]);

        return (int) $statement->fetchColumn();
    }

    private function findByColumn(string $organizationUid, string $column, string $value): ?Contact
    {
        $organizationId = $this->organizations->internalIdOf($organizationUid);

        $statement = $this->pdo->prepare(
            "SELECT * FROM kontor_contacts WHERE organization_id = :organization_id AND {$column} = :value AND deleted_at IS NULL LIMIT 1"
        );
        $statement->execute(['organization_id' => $organizationId, 'value' => $value]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    private function hydrate(array $row): Contact
    {
        $organizationUid = $this->organizationUidFor((int) $row['organization_id']);

        return new Contact(
            uid: Uid::fromString($row['uid']),
            organizationId: $organizationUid,
            type: $row['type'],
            firstName: $row['first_name'],
            middleName: $row['middle_name'],
            lastName: $row['last_name'],
            displayName: $row['display_name'],
            email: $row['email'],
            phone: $row['phone'],
            mobile: $row['mobile'],
            jobTitle: $row['job_title'],
            preferredLanguage: $row['preferred_language'],
            preferredCurrency: $row['preferred_currency'],
            source: $row['source'],
            status: $row['status'],
            assignedUserId: $row['assigned_user_id'] !== null ? (int) $row['assigned_user_id'] : null,
            notes: $row['notes'],
            metadata: $row['metadata_json'] !== null ? json_decode($row['metadata_json'], associative: true, flags: JSON_THROW_ON_ERROR) : [],
        );
    }

    private function organizationUidFor(int $organizationId): string
    {
        $statement = $this->pdo->prepare('SELECT uid FROM kontor_organizations WHERE id = :id');
        $statement->execute(['id' => $organizationId]);

        return (string) $statement->fetchColumn();
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
    }
}

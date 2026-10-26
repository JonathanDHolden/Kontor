<?php

declare(strict_types=1);

namespace Kontor\Contacts\Infrastructure\Persistence;

use Kontor\Contacts\Domain\Company;
use Kontor\Core\Infrastructure\Persistence\OrganizationRepository;
use Kontor\SDK\Contracts\RepositoryInterface;
use Kontor\SDK\ValueObjects\Uid;
use InvalidArgumentException;
use RuntimeException;

/**
 * kontor.md#12.2. Same RepositoryInterface + covariant-narrowing pattern
 * as ContactRepository.
 */
final class CompanyRepository implements RepositoryInterface
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    public function find(string $id): ?Company
    {
        $statement = $this->pdo->prepare('SELECT * FROM kontor_companies WHERE uid = :uid AND deleted_at IS NULL');
        $statement->execute(['uid' => $id]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function require(string $id): Company
    {
        return $this->find($id) ?? throw new RuntimeException("Company \"{$id}\" was not found.");
    }

    public function save(object $entity): void
    {
        if (!$entity instanceof Company) {
            throw new InvalidArgumentException('CompanyRepository::save() expects a Company.');
        }

        $organizationId = $this->organizations->internalIdOf($entity->organizationId);
        $now = $this->now();

        $statement = $this->pdo->prepare(
            'INSERT INTO kontor_companies
                (uid, organization_id, legal_name, trading_name, registration_number, tax_number, vat_number,
                 website, email, phone, preferred_language, preferred_currency, payment_terms_days,
                 credit_limit_minor, credit_limit_currency, status, assigned_user_id, notes, metadata_json,
                 created_at, updated_at, version)
             VALUES
                (:uid, :organization_id, :legal_name, :trading_name, :registration_number, :tax_number, :vat_number,
                 :website, :email, :phone, :preferred_language, :preferred_currency, :payment_terms_days,
                 :credit_limit_minor, :credit_limit_currency, :status, :assigned_user_id, :notes, :metadata_json,
                 :created_at, :updated_at, 1)
             ON DUPLICATE KEY UPDATE
                legal_name = VALUES(legal_name), trading_name = VALUES(trading_name),
                registration_number = VALUES(registration_number), tax_number = VALUES(tax_number),
                vat_number = VALUES(vat_number), website = VALUES(website), email = VALUES(email),
                phone = VALUES(phone), preferred_language = VALUES(preferred_language),
                preferred_currency = VALUES(preferred_currency), payment_terms_days = VALUES(payment_terms_days),
                credit_limit_minor = VALUES(credit_limit_minor), credit_limit_currency = VALUES(credit_limit_currency),
                status = VALUES(status), assigned_user_id = VALUES(assigned_user_id), notes = VALUES(notes),
                metadata_json = VALUES(metadata_json), updated_at = VALUES(updated_at), version = version + 1'
        );

        $statement->execute([
            'uid' => $entity->uid->toString(),
            'organization_id' => $organizationId,
            'legal_name' => $entity->legalName,
            'trading_name' => $entity->tradingName,
            'registration_number' => $entity->registrationNumber,
            'tax_number' => $entity->taxNumber,
            'vat_number' => $entity->vatNumber,
            'website' => $entity->website,
            'email' => $entity->email,
            'phone' => $entity->phone,
            'preferred_language' => $entity->preferredLanguage,
            'preferred_currency' => $entity->preferredCurrency,
            'payment_terms_days' => $entity->paymentTermsDays,
            'credit_limit_minor' => $entity->creditLimitMinor,
            'credit_limit_currency' => $entity->creditLimitCurrency,
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
        $statement = $this->pdo->prepare('UPDATE kontor_companies SET archived_at = :now WHERE uid = :uid');
        $statement->execute(['now' => $this->now(), 'uid' => $id]);
    }

    public function restore(string $id): void
    {
        $statement = $this->pdo->prepare('UPDATE kontor_companies SET archived_at = NULL WHERE uid = :uid');
        $statement->execute(['uid' => $id]);
    }

    public function delete(string $id): void
    {
        $statement = $this->pdo->prepare('UPDATE kontor_companies SET deleted_at = :now WHERE uid = :uid');
        $statement->execute(['now' => $this->now(), 'uid' => $id]);
    }

    public function findByVatNumber(string $organizationUid, string $vatNumber): ?Company
    {
        return $this->findByColumn($organizationUid, 'vat_number', $vatNumber);
    }

    public function findByEmail(string $organizationUid, string $email): ?Company
    {
        return $this->findByColumn($organizationUid, 'email', $email);
    }

    /**
     * @return Company[]
     */
    public function findAll(
        string $organizationUid,
        string $query = '',
        int $limit = 100,
        int $offset = 0,
        string $status = '',
    ): array
    {
        return $this->findList($organizationUid, $query, false, $limit, $offset, $status);
    }

    /**
     * @return Company[]
     */
    public function findArchived(
        string $organizationUid,
        string $query = '',
        int $limit = 100,
        int $offset = 0,
        string $status = '',
    ): array
    {
        return $this->findList($organizationUid, $query, true, $limit, $offset, $status);
    }

    /**
     * @return Company[]
     */
    private function findList(
        string $organizationUid,
        string $query,
        bool $archived,
        int $limit,
        int $offset,
        string $status,
    ): array
    {
        $organizationId = $this->organizations->internalIdOf($organizationUid);
        $query = trim($query);
        $limit = max(1, min($limit, 250));
        $offset = max(0, $offset);
        $sql = 'SELECT * FROM kontor_companies
            WHERE organization_id = :organization_id
              AND deleted_at IS NULL
              AND archived_at IS ' . ($archived ? 'NOT NULL' : 'NULL');

        if ($query !== '') {
            $sql .= ' AND (
                legal_name LIKE :query
                OR trading_name LIKE :query
                OR email LIKE :query
                OR phone LIKE :query
                OR registration_number LIKE :query
                OR metadata_json LIKE :query
            )';
        }

        if ($status !== '') {
            $sql .= ' AND status = :status';
        }

        $sql .= ' ORDER BY updated_at DESC, legal_name ASC LIMIT :limit OFFSET :offset';
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
            fn (array $row): Company => $this->hydrate($row),
            $statement->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function countMatching(
        string $organizationUid,
        string $query = '',
        bool $archived = false,
        string $status = '',
    ): int
    {
        $organizationId = $this->organizations->internalIdOf($organizationUid);
        $query = trim($query);
        $sql = 'SELECT COUNT(*) FROM kontor_companies
            WHERE organization_id = :organization_id
              AND deleted_at IS NULL
              AND archived_at IS ' . ($archived ? 'NOT NULL' : 'NULL');

        if ($query !== '') {
            $sql .= ' AND (
                legal_name LIKE :query
                OR trading_name LIKE :query
                OR email LIKE :query
                OR phone LIKE :query
                OR registration_number LIKE :query
                OR metadata_json LIKE :query
            )';
        }

        if ($status !== '') {
            $sql .= ' AND status = :status';
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
            'SELECT COUNT(*) FROM kontor_companies
             WHERE organization_id = :organization_id
               AND deleted_at IS NULL
               AND archived_at IS NULL'
        );
        $statement->execute([
            'organization_id' => $this->organizations->internalIdOf($organizationUid),
        ]);

        return (int) $statement->fetchColumn();
    }

    private function findByColumn(string $organizationUid, string $column, string $value): ?Company
    {
        $organizationId = $this->organizations->internalIdOf($organizationUid);

        $statement = $this->pdo->prepare(
            "SELECT * FROM kontor_companies WHERE organization_id = :organization_id AND {$column} = :value AND deleted_at IS NULL LIMIT 1"
        );
        $statement->execute(['organization_id' => $organizationId, 'value' => $value]);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    private function hydrate(array $row): Company
    {
        return new Company(
            uid: Uid::fromString($row['uid']),
            organizationId: $this->organizationUidFor((int) $row['organization_id']),
            legalName: $row['legal_name'],
            tradingName: $row['trading_name'],
            registrationNumber: $row['registration_number'],
            taxNumber: $row['tax_number'],
            vatNumber: $row['vat_number'],
            website: $row['website'],
            email: $row['email'],
            phone: $row['phone'],
            preferredLanguage: $row['preferred_language'],
            preferredCurrency: $row['preferred_currency'],
            paymentTermsDays: $row['payment_terms_days'] !== null ? (int) $row['payment_terms_days'] : null,
            creditLimitMinor: $row['credit_limit_minor'] !== null ? (int) $row['credit_limit_minor'] : null,
            creditLimitCurrency: $row['credit_limit_currency'],
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

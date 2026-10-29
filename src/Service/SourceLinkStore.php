<?php declare(strict_types=1);

namespace DhGithubUpdater\Service;

use DhGithubUpdater\Github\GitRef;
use DhGithubUpdater\Github\RepositoryReference;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Persistiert die Verknüpfung Plugin <-> GitHub-Repository.
 *
 * Bewusst ohne DAL-Entity: Schreibzugriffe sind nur über die validierenden
 * Endpunkte dieses Plugins möglich und nicht über die generische Admin-API.
 */
class SourceLinkStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{pluginName: string, repository: string, installedRef: ?string, installedRefType: ?string, installedCommit: ?string}>
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT p.`name` AS pluginName, s.`repository`, s.`installed_ref` AS installedRef,
                   s.`installed_ref_type` AS installedRefType, s.`installed_commit` AS installedCommit
            FROM `dh_github_updater_source` s
            INNER JOIN `plugin` p ON p.`id` = s.`plugin_id`
            ORDER BY p.`name`
            SQL);

        return array_map(static fn (array $row): array => [
            'pluginName' => (string) $row['pluginName'],
            'repository' => (string) $row['repository'],
            'installedRef' => $row['installedRef'] !== null ? (string) $row['installedRef'] : null,
            'installedRefType' => $row['installedRefType'] !== null ? (string) $row['installedRefType'] : null,
            'installedCommit' => $row['installedCommit'] !== null ? (string) $row['installedCommit'] : null,
        ], $rows);
    }

    /**
     * @return array{repository: RepositoryReference, installedRef: ?string, installedRefType: ?string, installedCommit: ?string}|null
     */
    public function findByPluginName(string $pluginName): ?array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
            SELECT s.`repository`, s.`installed_ref`, s.`installed_ref_type`, s.`installed_commit`
            FROM `dh_github_updater_source` s
            INNER JOIN `plugin` p ON p.`id` = s.`plugin_id`
            WHERE p.`name` = :name
            SQL, ['name' => $pluginName]);

        if ($row === false) {
            return null;
        }

        return [
            'repository' => RepositoryReference::fromFullName((string) $row['repository']),
            'installedRef' => $row['installed_ref'] !== null ? (string) $row['installed_ref'] : null,
            'installedRefType' => $row['installed_ref_type'] !== null ? (string) $row['installed_ref_type'] : null,
            'installedCommit' => $row['installed_commit'] !== null ? (string) $row['installed_commit'] : null,
        ];
    }

    public function link(string $pluginId, RepositoryReference $repository): void
    {
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO `dh_github_updater_source` (`id`, `plugin_id`, `repository`, `created_at`)
            VALUES (:id, :pluginId, :repository, :now)
            ON DUPLICATE KEY UPDATE
                `installed_ref` = IF(`repository` = VALUES(`repository`), `installed_ref`, NULL),
                `installed_ref_type` = IF(`repository` = VALUES(`repository`), `installed_ref_type`, NULL),
                `installed_commit` = IF(`repository` = VALUES(`repository`), `installed_commit`, NULL),
                `repository` = VALUES(`repository`),
                `updated_at` = :now
            SQL, [
            'id' => Uuid::randomBytes(),
            'pluginId' => Uuid::fromHexToBytes($pluginId),
            'repository' => $repository->getFullName(),
            'now' => $now,
        ]);
    }

    public function recordInstallation(string $pluginId, RepositoryReference $repository, GitRef $ref): void
    {
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO `dh_github_updater_source`
                (`id`, `plugin_id`, `repository`, `installed_ref`, `installed_ref_type`, `installed_commit`, `created_at`)
            VALUES (:id, :pluginId, :repository, :ref, :refType, :commit, :now)
            ON DUPLICATE KEY UPDATE
                `repository` = VALUES(`repository`),
                `installed_ref` = VALUES(`installed_ref`),
                `installed_ref_type` = VALUES(`installed_ref_type`),
                `installed_commit` = VALUES(`installed_commit`),
                `updated_at` = :now
            SQL, [
            'id' => Uuid::randomBytes(),
            'pluginId' => Uuid::fromHexToBytes($pluginId),
            'repository' => $repository->getFullName(),
            'ref' => $ref->name,
            'refType' => $ref->type,
            'commit' => $ref->commitSha,
            'now' => $now,
        ]);
    }

    /**
     * Verknüpfung nach einer Umbenennung des Repositorys auf GitHub auf den neuen Namen umstellen.
     * Installierte Version bleibt erhalten – es ist dasselbe Repository.
     */
    public function renameRepository(string $pluginName, string $from, RepositoryReference $to): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE `dh_github_updater_source` s
            INNER JOIN `plugin` p ON p.`id` = s.`plugin_id`
            SET s.`repository` = :to, s.`updated_at` = :now
            WHERE p.`name` = :name AND LOWER(s.`repository`) = LOWER(:from)
            SQL, [
            'to' => $to->getFullName(),
            'now' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'name' => $pluginName,
            'from' => $from,
        ]);
    }

    /**
     * Nach dem Zurückspielen einer Sicherung wieder den vorherigen Installationsstand vermerken.
     */
    public function restoreInstallation(string $pluginId, ?string $ref, ?string $refType, ?string $commit): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE `dh_github_updater_source`
            SET `installed_ref` = :ref, `installed_ref_type` = :refType, `installed_commit` = :commit, `updated_at` = :now
            WHERE `plugin_id` = :pluginId
            SQL, [
            'ref' => $ref,
            'refType' => $refType,
            'commit' => $commit,
            'now' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'pluginId' => Uuid::fromHexToBytes($pluginId),
        ]);
    }

    public function unlink(string $pluginId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM `dh_github_updater_source` WHERE `plugin_id` = :pluginId',
            ['pluginId' => Uuid::fromHexToBytes($pluginId)],
        );
    }
}

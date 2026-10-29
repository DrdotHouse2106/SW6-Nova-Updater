<?php declare(strict_types=1);

namespace DhGithubUpdater\Config;

use DhGithubUpdater\Security\RepositoryAllowList;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Typisierter Zugriff auf die Plugin-Konfiguration.
 */
class UpdaterConfig
{
    private const PREFIX = 'DhGithubUpdater.config.';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function getToken(): ?string
    {
        $token = trim((string) $this->systemConfigService->getString(self::PREFIX . 'githubToken'));

        return $token !== '' && preg_match('/^[A-Za-z0-9_]{20,255}$/', $token) ? $token : null;
    }

    public function getAllowList(): RepositoryAllowList
    {
        return new RepositoryAllowList($this->systemConfigService->getString(self::PREFIX . 'allowedRepositories'));
    }

    public function isBranchInstallAllowed(): bool
    {
        return $this->systemConfigService->getBool(self::PREFIX . 'allowBranches');
    }

    public function isAdminOnly(): bool
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'adminOnly');

        return $value === null || (bool) $value;
    }

    public function getMaxDownloadBytes(): int
    {
        $mb = $this->systemConfigService->getInt(self::PREFIX . 'maxDownloadSizeMb');

        return max(1, min($mb > 0 ? $mb : 50, 500)) * 1024 * 1024;
    }

    public function isAutoCheckEnabled(): bool
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'autoCheck');

        return $value === null || (bool) $value;
    }

    public function isUpdateNotificationEnabled(): bool
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'notifyUpdates');

        return $value === null || (bool) $value;
    }

    /**
     * Bereits gemeldete Versionen (Plugin-Name => Version), damit jede Version nur einmal gemeldet wird.
     *
     * @return array<string, string>
     */
    public function getNotifiedVersions(): array
    {
        $value = $this->systemConfigService->get('DhGithubUpdater.state.notifiedVersions');

        return \is_array($value) ? array_filter($value, 'is_string') : [];
    }

    /**
     * @param array<string, string> $versions
     */
    public function setNotifiedVersions(array $versions): void
    {
        $this->systemConfigService->set('DhGithubUpdater.state.notifiedVersions', $versions);
    }

    /**
     * Updates, deren Shopware-Lebenszyklus (Schritt 2) noch aussteht, samt aufbewahrter Sicherung.
     *
     * @return array<string, array{backup: string, createdAt: int, fromVersion: string, ref: ?string, refType: ?string, commit: ?string}>
     */
    public function getPendingUpdates(): array
    {
        $value = $this->systemConfigService->get('DhGithubUpdater.state.pendingUpdates');
        if (!\is_array($value)) {
            return [];
        }

        $pending = [];
        foreach ($value as $pluginName => $entry) {
            if (!\is_string($pluginName) || !\is_array($entry) || !\is_string($entry['backup'] ?? null) || !\is_int($entry['createdAt'] ?? null)) {
                continue;
            }
            $pending[$pluginName] = [
                'backup' => $entry['backup'],
                'createdAt' => $entry['createdAt'],
                'fromVersion' => \is_string($entry['fromVersion'] ?? null) ? $entry['fromVersion'] : '',
                'ref' => \is_string($entry['ref'] ?? null) ? $entry['ref'] : null,
                'refType' => \is_string($entry['refType'] ?? null) ? $entry['refType'] : null,
                'commit' => \is_string($entry['commit'] ?? null) ? $entry['commit'] : null,
            ];
        }

        return $pending;
    }

    /**
     * @param array<string, array{backup: string, createdAt: int, fromVersion: string, ref: ?string, refType: ?string, commit: ?string}> $pending
     */
    public function setPendingUpdates(array $pending): void
    {
        $this->systemConfigService->set('DhGithubUpdater.state.pendingUpdates', $pending);
    }

    public function isRemoteCatalogEnabled(): bool
    {
        $value = $this->systemConfigService->get(self::PREFIX . 'remoteCatalog');

        return $value === null || (bool) $value;
    }

    public function getMaxTagsToCheck(): int
    {
        $count = $this->systemConfigService->getInt(self::PREFIX . 'maxTagsToCheck');

        return max(1, min($count > 0 ? $count : 15, 50));
    }
}

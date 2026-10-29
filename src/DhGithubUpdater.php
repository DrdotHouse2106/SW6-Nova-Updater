<?php declare(strict_types=1);

namespace DhGithubUpdater;

use DhGithubUpdater\Config\DefaultAllowList;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class DhGithubUpdater extends Plugin
{
    private const ALLOW_LIST_KEY = 'DhGithubUpdater.config.allowedRepositories';

    public function postInstall(InstallContext $installContext): void
    {
        parent::postInstall($installContext);

        $this->fillEmptyAllowList();
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $this->fillEmptyAllowList();
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container?->get(Connection::class);
        if (!$connection instanceof Connection) {
            return;
        }

        $connection->executeStatement('DROP TABLE IF EXISTS `dh_github_updater_source`');
    }

    public function executeComposerCommands(): bool
    {
        return false;
    }

    /**
     * Eine leere Liste erlaubter Repositories bedeutet „alles erlaubt“. Sie wird einmalig vorbelegt;
     * eine bereits gepflegte Liste bleibt unverändert.
     */
    private function fillEmptyAllowList(): void
    {
        $systemConfig = $this->container?->get(SystemConfigService::class);
        if (!$systemConfig instanceof SystemConfigService) {
            return;
        }

        $current = $systemConfig->get(self::ALLOW_LIST_KEY);
        if (\is_string($current) && trim($current) !== '') {
            return;
        }

        $linked = [];
        $connection = $this->container?->get(Connection::class);
        if ($connection instanceof Connection) {
            try {
                $linked = array_map('strval', $connection->fetchFirstColumn('SELECT `repository` FROM `dh_github_updater_source`'));
            } catch (\Throwable) {
                // Tabelle fehlt (z. B. Installation ohne Migration) – nur die Vorbelegung
            }
        }

        $systemConfig->set(self::ALLOW_LIST_KEY, DefaultAllowList::build($linked));
    }
}

<?php declare(strict_types=1);

namespace DhGithubUpdater\Service;

use Composer\IO\NullIO;
use DhGithubUpdater\Catalog\CatalogParser;
use DhGithubUpdater\Catalog\CatalogProvider;
use DhGithubUpdater\Config\UpdaterConfig;
use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Github\GitRef;
use DhGithubUpdater\Github\GithubApiClient;
use DhGithubUpdater\Github\RepositoryReference;
use DhGithubUpdater\Package\CompatibilityChecker;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Adapter\Cache\CacheClearer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Plugin\PluginService;
use Symfony\Component\Lock\LockFactory;

/**
 * Orchestriert Installation, Update und Update-Prüfung von GitHub-Plugins.
 *
 * Die eigentlichen Lebenszyklus-Aktionen (installieren, aktivieren, Update
 * ausführen) übernimmt anschließend Shopware selbst über die regulären,
 * ACL-geschützten Extension-Endpunkte. Es werden keine Shell-Befehle ausgeführt.
 */
class GithubPluginManager
{
    private const LOCK_NAME = 'dh-github-updater-deploy';

    /**
     * @param EntityRepository<\Shopware\Core\Framework\Plugin\PluginCollection> $pluginRepository
     */
    public function __construct(
        private readonly VersionResolver $versionResolver,
        private readonly GithubApiClient $client,
        private readonly PluginDeployer $deployer,
        private readonly SourceLinkStore $sourceLinkStore,
        private readonly UpdaterConfig $config,
        private readonly CompatibilityChecker $compatibilityChecker,
        private readonly PluginService $pluginService,
        private readonly EntityRepository $pluginRepository,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly ConnectionTester $connectionTester,
        private readonly CatalogProvider $catalogProvider,
        private readonly ?CacheClearer $cacheClearer = null,
    ) {
    }

    /**
     * Plugin-Katalog inkl. Status im Shop (installiert, verknüpft, erlaubt).
     *
     * @return array<string, mixed>
     */
    public function catalog(bool $refresh, Context $context): array
    {
        $catalog = $this->catalogProvider->load($refresh);
        $allowList = $this->config->getAllowList();

        $names = array_map(static fn ($entry): string => $entry->pluginName, $catalog['entries']);
        $installed = [];
        if ($names !== []) {
            $criteria = (new Criteria())->addFilter(new EqualsAnyFilter('name', $names));
            foreach ($this->pluginRepository->search($criteria, $context)->getEntities() as $plugin) {
                /** @var PluginEntity $plugin */
                $installed[strtolower($plugin->getName())] = [
                    'version' => $plugin->getVersion(),
                    'installed' => $plugin->getInstalledAt() !== null,
                    'active' => $plugin->getActive(),
                ];
            }
        }

        $links = [];
        foreach ($this->canonicalLinks($catalog['entries']) as $link) {
            $links[strtolower($link['pluginName'])] = $link['repository'];
        }

        $plugins = [];
        foreach ($catalog['entries'] as $entry) {
            $key = strtolower($entry->pluginName);
            $plugins[] = $entry->toArray() + [
                'allowed' => $allowList->isAllowed($entry->repository),
                'plugin' => $installed[$key] ?? null,
                'linkedRepository' => $links[$key] ?? null,
            ];
        }

        return [
            'source' => $catalog['source'],
            'categories' => CatalogParser::CATEGORIES,
            'plugins' => $plugins,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listVersions(string $repositoryInput): array
    {
        $repository = RepositoryReference::fromUserInput($repositoryInput);
        $this->config->getAllowList()->assertAllowed($repository);

        return [
            'repository' => $repository->getFullName(),
            'repositoryUrl' => $repository->getUrl(),
            'shopwareVersion' => $this->compatibilityChecker->getShopwareVersion(),
            'branchesAllowed' => $this->config->isBranchInstallAllowed(),
            'versions' => array_map(
                static fn (VersionCandidate $candidate): array => $candidate->toArray(),
                $this->versionResolver->listCompatible($repository),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function install(string $repositoryInput, string $refName, string $refType, Context $context): array
    {
        $repository = RepositoryReference::fromUserInput($repositoryInput);
        $this->config->getAllowList()->assertAllowed($repository);

        return $this->locked(function () use ($repository, $refName, $refType, $context): array {
            $candidate = $this->versionResolver->resolve($repository, $refName, $refType);
            $pluginName = $candidate->manifest->pluginName;

            if ($this->findPlugin($pluginName, $context) !== null) {
                throw new GithubUpdaterException(\sprintf('Ein Plugin mit dem Namen "%s" ist bereits vorhanden. Bitte die Update-Funktion verwenden.', $pluginName), 409);
            }

            $package = $this->downloadAndStage($repository, $candidate);

            try {
                $this->deployer->deployNew($package);
            } finally {
                $this->deployer->discard($package->path);
            }

            $this->refresh($context);

            $plugin = $this->findPlugin($pluginName, $context);
            if ($plugin === null) {
                throw new GithubUpdaterException('Das Plugin wurde abgelegt, von Shopware aber nicht erkannt. Bitte die Logs prüfen.', 500);
            }

            $this->discardCompiledContainer($pluginName);

            $this->sourceLinkStore->recordInstallation($plugin->getId(), $repository, $candidate->ref);

            $this->logger->info('DhGithubUpdater: Plugin aus GitHub abgelegt.', [
                'plugin' => $pluginName,
                'repository' => $repository->getFullName(),
                'ref' => $candidate->ref->name,
                'commit' => $candidate->ref->commitSha,
                'userId' => $this->getUserId($context),
            ]);

            return [
                'pluginName' => $pluginName,
                'version' => $package->manifest->version ?? $candidate->manifest->version,
                'ref' => $candidate->ref->name,
                'commit' => $candidate->ref->commitSha,
                'warnings' => $package->warnings,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function update(string $pluginName, string $refName, string $refType, Context $context): array
    {
        return $this->locked(function () use ($pluginName, $refName, $refType, $context): array {
            $plugin = $this->requirePlugin($pluginName, $context);
            $repository = $this->requireLinkedRepository($plugin);

            $candidate = $this->versionResolver->resolve($repository, $refName, $refType);
            $this->assertMatchesPlugin($candidate, $plugin);

            if ($refType === GitRef::TYPE_TAG && $candidate->manifest->version !== null
                && (CompatibilityChecker::compare($candidate->manifest->version, $plugin->getVersion()) ?? 0) < 0
            ) {
                throw new GithubUpdaterException('Ein Downgrade auf eine ältere Version wird nicht unterstützt.', 409);
            }

            $package = $this->downloadAndStage($repository, $candidate);

            $backup = '';
            try {
                if ($package->manifest->pluginName !== $plugin->getName() || $package->manifest->pluginClass !== $plugin->getBaseClass()) {
                    throw new GithubUpdaterException('Das Archiv enthält ein anderes Plugin als erwartet.', 422);
                }

                $backup = $this->deployer->replaceExisting($package, (string) $plugin->getPath());

                try {
                    $this->refresh($context);
                } catch (\Throwable $e) {
                    // Die Sicherung wird auf keinen Fall gelöscht, falls die Wiederherstellung scheitert.
                    $restoreFrom = $backup;
                    $backup = '';
                    try {
                        $this->deployer->restoreBackup($restoreFrom, $plugin->getName());
                        $this->refresh($context);
                    } catch (\Throwable $restoreError) {
                        $this->logger->critical('DhGithubUpdater: Wiederherstellung fehlgeschlagen, Sicherung liegt unter ' . $restoreFrom, ['error' => $restoreError->getMessage()]);

                        throw new GithubUpdaterException('Update und Wiederherstellung fehlgeschlagen. Die Sicherung liegt in custom/plugins/.dh-github-updater.', 500, $e);
                    }

                    throw new GithubUpdaterException('Shopware konnte die neue Version nicht einlesen. Der vorherige Stand wurde wiederhergestellt.', 500, $e);
                }
            } finally {
                $this->deployer->discard($package->path);
                $this->deployer->discard($backup);
            }

            $this->sourceLinkStore->recordInstallation($plugin->getId(), $repository, $candidate->ref);

            $this->logger->info('DhGithubUpdater: Plugin-Dateien aus GitHub aktualisiert.', [
                'plugin' => $pluginName,
                'repository' => $repository->getFullName(),
                'ref' => $candidate->ref->name,
                'commit' => $candidate->ref->commitSha,
                'userId' => $this->getUserId($context),
            ]);

            return [
                'pluginName' => $pluginName,
                'version' => $candidate->manifest->version,
                'ref' => $candidate->ref->name,
                'commit' => $candidate->ref->commitSha,
                'installed' => $plugin->getInstalledAt() !== null,
                'warnings' => $package->warnings,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function check(string $pluginName, Context $context): array
    {
        $plugin = $this->requirePlugin($pluginName, $context);
        $link = $this->sourceLinkStore->findByPluginName($plugin->getName());
        if ($link === null) {
            throw new GithubUpdaterException('Für dieses Plugin ist kein GitHub-Repository hinterlegt.', 404);
        }

        $this->config->getAllowList()->assertAllowed($link['repository']);

        $installedVersion = $plugin->getVersion();
        $updates = [];
        $latestVersion = null;
        foreach ($this->versionResolver->listCompatible($link['repository'], $installedVersion) as $candidate) {
            if ($candidate->manifest->pluginName !== $plugin->getName()) {
                continue;
            }

            if ($candidate->ref->type !== GitRef::TYPE_BRANCH) {
                $version = $candidate->manifest->version ?? $candidate->ref->name;
                if ($latestVersion === null || (CompatibilityChecker::compare($version, $latestVersion) ?? 0) > 0) {
                    $latestVersion = $version;
                }
            }

            if ($candidate->ref->type === GitRef::TYPE_BRANCH) {
                // Branch-Stände nur anbieten, wenn das Plugin bereits aus einem Branch stammt.
                if ($link['installedRefType'] === GitRef::TYPE_BRANCH && $candidate->ref->commitSha !== $link['installedCommit']) {
                    $updates[] = $candidate->toArray();
                }

                continue;
            }

            $newer = $candidate->manifest->version !== null
                ? CompatibilityChecker::compare($candidate->manifest->version, $installedVersion)
                : CompatibilityChecker::compare($candidate->ref->name, $installedVersion);
            if ($newer !== null && $newer > 0) {
                $updates[] = $candidate->toArray();
            }
        }

        return [
            'pluginName' => $plugin->getName(),
            'repository' => $link['repository']->getFullName(),
            'repositoryUrl' => $link['repository']->getUrl(),
            'installedVersion' => $installedVersion,
            'installedRef' => $link['installedRef'],
            'installedCommit' => $link['installedCommit'],
            // null = im Repository keine kompatible Release-Version gefunden
            'latestVersion' => $latestVersion,
            'updates' => $updates,
        ];
    }

    /**
     * Release-Notizen zwischen zwei Versionen: alles nach $from bis einschließlich $to
     * (ohne $from: ab der installierten Version, ohne $to: bis zur neuesten).
     *
     * @return array{pluginName: string, repository: string, from: ?string, to: ?string, releases: list<array{version: string, tag: string, name: string, body: string, publishedAt: ?string, url: ?string}>}
     */
    public function changelog(string $pluginName, ?string $from, ?string $to, Context $context): array
    {
        $plugin = $this->requirePlugin($pluginName, $context);
        $link = $this->sourceLinkStore->findByPluginName($plugin->getName());
        if ($link === null) {
            throw new GithubUpdaterException('Für dieses Plugin ist kein GitHub-Repository hinterlegt.', 404);
        }

        $this->config->getAllowList()->assertAllowed($link['repository']);
        $from = $from !== null && $from !== '' ? $from : $plugin->getVersion();

        $releases = [];
        foreach ($this->client->listReleaseNotes($link['repository'], $this->config->getToken()) as $release) {
            $version = ltrim($release['tag'], 'vV');
            if ((CompatibilityChecker::compare($version, $from) ?? -1) <= 0) {
                continue;
            }
            if ($to !== null && $to !== '' && (CompatibilityChecker::compare($version, $to) ?? 1) > 0) {
                continue;
            }

            $releases[] = ['version' => $version] + $release;
        }

        usort($releases, static fn (array $a, array $b): int => CompatibilityChecker::compare($b['version'], $a['version']) ?? 0);

        return [
            'pluginName' => $plugin->getName(),
            'repository' => $link['repository']->getFullName(),
            'from' => $from,
            'to' => $to !== '' ? $to : null,
            'releases' => \array_slice($releases, 0, 30),
        ];
    }

    public function link(string $pluginName, ?string $repositoryInput, Context $context): ?string
    {
        $plugin = $this->requirePlugin($pluginName, $context);

        if ($repositoryInput === null || trim($repositoryInput) === '') {
            $this->sourceLinkStore->unlink($plugin->getId());

            return null;
        }

        $repository = RepositoryReference::fromUserInput($repositoryInput);
        $this->config->getAllowList()->assertAllowed($repository);
        $this->sourceLinkStore->link($plugin->getId(), $repository);

        return $repository->getFullName();
    }

    /**
     * „Verbindung testen“ – liest nur, installiert nichts.
     *
     * @return array<string, mixed>
     */
    public function testConnection(string $repositoryInput, ?string $pluginName, Context $context): array
    {
        $plugin = null;
        if ($pluginName !== null && $pluginName !== '') {
            $plugin = $this->requirePlugin($pluginName, $context);
        }

        return $this->connectionTester->test(
            $repositoryInput,
            $plugin?->getName(),
            $plugin?->getBaseClass(),
            $plugin?->getVersion(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sources(): array
    {
        try {
            return $this->canonicalLinks($this->catalogProvider->load()['entries']);
        } catch (\Throwable) {
            return $this->sourceLinkStore->all();
        }
    }

    /**
     * Verknüpfungen mit einem früheren Repository-Namen (laut Katalog) auf den neuen Namen umstellen.
     *
     * @param list<\DhGithubUpdater\Catalog\CatalogEntry> $entries
     *
     * @return list<array{pluginName: string, repository: string, installedRef: ?string, installedRefType: ?string, installedCommit: ?string}>
     */
    private function canonicalLinks(array $entries): array
    {
        $byPlugin = [];
        foreach ($entries as $entry) {
            $byPlugin[strtolower($entry->pluginName)] = $entry;
        }

        $links = $this->sourceLinkStore->all();
        foreach ($links as $index => $link) {
            $entry = $byPlugin[strtolower($link['pluginName'])] ?? null;
            if ($entry === null || !$entry->isFormerRepository($link['repository'])) {
                continue;
            }

            $this->sourceLinkStore->renameRepository($link['pluginName'], $link['repository'], $entry->repository);
            $links[$index]['repository'] = $entry->repository->getFullName();
        }

        return $links;
    }

    /**
     * Bevorzugt das fertige Plugin-ZIP aus dem GitHub-Release (enthält bei vielen
     * Herstellern die gebaute Administration und vendor/). Ist es unbrauchbar,
     * wird der Quellcode des geprüften Commits verwendet.
     */
    private function downloadAndStage(RepositoryReference $repository, VersionCandidate $candidate): StagedPackage
    {
        $asset = $candidate->asset;
        if ($asset !== null) {
            try {
                return $this->stageVerified($candidate, function (string $zipFile) use ($repository, $asset): void {
                    $this->client->downloadReleaseAsset($repository, $asset, $zipFile, $this->config->getMaxDownloadBytes(), $this->config->getToken());
                });
            } catch (GithubUpdaterException $e) {
                $this->logger->warning('DhGithubUpdater: Release-Paket nicht verwendbar, nutze Quellcode des Tags.', [
                    'repository' => $repository->getFullName(),
                    'asset' => $asset->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->stageVerified($candidate, function (string $zipFile) use ($repository, $candidate): void {
            $this->client->downloadZipball($repository, $candidate->ref->commitSha, $zipFile, $this->config->getMaxDownloadBytes(), $this->config->getToken());
        });
    }

    /**
     * @param callable(string): void $download
     */
    private function stageVerified(VersionCandidate $candidate, callable $download): StagedPackage
    {
        $zipFile = tempnam(sys_get_temp_dir(), 'dh-ghu-');
        if ($zipFile === false) {
            throw new GithubUpdaterException('Temporäre Datei konnte nicht angelegt werden.', 500);
        }

        try {
            $download($zipFile);
            $package = $this->deployer->stage($zipFile);
        } finally {
            @unlink($zipFile);
        }

        if ($package->manifest->pluginName !== $candidate->manifest->pluginName
            || $package->manifest->pluginClass !== $candidate->manifest->pluginClass
        ) {
            $this->deployer->discard($package->path);

            throw new GithubUpdaterException('Der Archivinhalt stimmt nicht mit der geprüften Version überein.', 422);
        }

        return $package;
    }

    private function assertMatchesPlugin(VersionCandidate $candidate, PluginEntity $plugin): void
    {
        if ($candidate->manifest->pluginName !== $plugin->getName() || $candidate->manifest->pluginClass !== $plugin->getBaseClass()) {
            throw new GithubUpdaterException(\sprintf(
                'Das Repository enthält das Plugin "%s" und nicht "%s".',
                $candidate->manifest->pluginName,
                $plugin->getName(),
            ), 422);
        }
    }

    private function requireLinkedRepository(PluginEntity $plugin): RepositoryReference
    {
        $link = $this->sourceLinkStore->findByPluginName($plugin->getName());
        if ($link === null) {
            throw new GithubUpdaterException('Für dieses Plugin ist kein GitHub-Repository hinterlegt.', 404);
        }

        $this->config->getAllowList()->assertAllowed($link['repository']);

        return $link['repository'];
    }

    private function requirePlugin(string $pluginName, Context $context): PluginEntity
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', $pluginName)) {
            throw new GithubUpdaterException('Ungültiger Plugin-Name.');
        }

        $plugin = $this->findPlugin($pluginName, $context);
        if ($plugin === null) {
            throw new GithubUpdaterException('Plugin nicht gefunden.', 404);
        }

        return $plugin;
    }

    private function findPlugin(string $pluginName, Context $context): ?PluginEntity
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('name', $pluginName))->setLimit(1);

        /** @var PluginEntity|null $plugin */
        $plugin = $this->pluginRepository->search($criteria, $context)->first();

        return $plugin;
    }

    private function refresh(Context $context): void
    {
        $errors = $this->pluginService->refreshPlugins($context, new NullIO());
        foreach ($errors as $error) {
            $this->logger->warning('DhGithubUpdater: Fehler beim Einlesen der Plugins.', ['error' => $error->getMessage()]);
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    /**
     * Verwirft Shopwares kompilierten Service-Container, damit die nächste Anfrage (Lebenszyklus-Schritt,
     * Shop, Administration) ihn mit den neuen Plugin-Dateien neu aufbaut. Sonst bekommen Klassen, deren
     * Konstruktor sich geändert hat, weiter die alten Argumente – jede Anfrage endet mit Fehler 500,
     * bis jemand var/cache von Hand löscht.
     */
    private function discardCompiledContainer(string $pluginName): void
    {
        if ($this->cacheClearer === null) {
            return;
        }

        try {
            $this->cacheClearer->clearContainerCache();
        } catch (\Throwable $e) {
            $this->logger->warning('DhGithubUpdater: Service-Container konnte nach dem Update nicht verworfen werden – ggf. bin/console cache:clear ausführen.', [
                'plugin' => $pluginName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function locked(callable $callback): mixed
    {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME, 600);
        if (!$lock->acquire()) {
            throw new GithubUpdaterException('Es läuft bereits eine Installation oder ein Update. Bitte kurz warten.', 409);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function getUserId(Context $context): ?string
    {
        $source = $context->getSource();

        return $source instanceof \Shopware\Core\Framework\Api\Context\AdminApiSource ? $source->getUserId() : null;
    }
}

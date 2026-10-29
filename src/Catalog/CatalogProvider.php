<?php declare(strict_types=1);

namespace DhGithubUpdater\Catalog;

use DhGithubUpdater\Config\UpdaterConfig;
use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Github\GithubApiClient;
use DhGithubUpdater\Github\RepositoryReference;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Stellt den Plugin-Katalog bereit.
 *
 * Standardmäßig wird die aktuelle Fassung aus dem Repository des Updaters
 * geladen (über api.github.com, 6 Stunden zwischengespeichert). So erscheinen
 * neu beigetragene Plugins, ohne dass der Updater selbst aktualisiert werden
 * muss. Ist GitHub nicht erreichbar oder der Abruf abgeschaltet, wird die
 * mitgelieferte Fassung verwendet.
 */
class CatalogProvider
{
    public const REPOSITORY = 'DrdotHouse2106/SW6-Nova-Updater';
    public const PATH = 'src/Resources/catalog/plugins.json';

    private const CACHE_KEY = 'dh_github_updater_catalog_v1';
    private const CACHE_TTL = 21600;
    private const MAX_BYTES = 512 * 1024;

    public function __construct(
        private readonly GithubApiClient $client,
        private readonly UpdaterConfig $config,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $bundledFile = __DIR__ . '/../Resources/catalog/plugins.json',
    ) {
    }

    /**
     * @return array{source: string, entries: list<CatalogEntry>}
     */
    public function load(bool $forceRefresh = false): array
    {
        if ($this->config->isRemoteCatalogEnabled()) {
            $remote = $this->loadRemote($forceRefresh);
            if ($remote !== null) {
                return ['source' => 'remote', 'entries' => $remote];
            }
        }

        return ['source' => 'bundled', 'entries' => $this->loadBundled()];
    }

    /**
     * Die mit dieser Updater-Version ausgelieferte Fassung. Nur sie darf bestehende
     * Verknüpfungen umstellen – der Online-Katalog dient ausschließlich der Anzeige.
     *
     * @return list<CatalogEntry>
     */
    public function loadBundled(): array
    {
        return CatalogParser::parse((string) file_get_contents($this->bundledFile));
    }

    /**
     * @return list<CatalogEntry>|null
     */
    private function loadRemote(bool $forceRefresh): ?array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if (!$forceRefresh && $item->isHit() && \is_string($item->get())) {
            return CatalogParser::parse($item->get());
        }

        try {
            $json = $this->client->getFileContents(
                RepositoryReference::fromFullName(self::REPOSITORY),
                self::PATH,
                $this->config->getToken(),
                self::MAX_BYTES,
            );
            if ($json === null) {
                return null;
            }

            $entries = CatalogParser::parse($json);
        } catch (GithubUpdaterException $e) {
            $this->logger->info('DhGithubUpdater: Online-Katalog nicht verfügbar, nutze mitgelieferte Fassung.', ['error' => $e->getMessage()]);

            return null;
        }

        $item->set($json);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return $entries;
    }
}

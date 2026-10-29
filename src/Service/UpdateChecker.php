<?php declare(strict_types=1);

namespace DhGithubUpdater\Service;

use DhGithubUpdater\Config\UpdaterConfig;
use DhGithubUpdater\Exception\GithubUpdaterException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Automatische Update-Prüfung aller mit GitHub verknüpften Plugins.
 *
 * - Läuft als geplante Aufgabe alle 12 Stunden und meldet neue Versionen
 *   einmalig über die Benachrichtigungen (Glocke) der Administration.
 * - Ohne laufende geplante Aufgaben (kein Cron/Worker) wird beim Öffnen von
 *   „Meine Erweiterungen“ geprüft, sobald das letzte Ergebnis älter als 12 Stunden ist.
 */
class UpdateChecker
{
    public const INTERVAL = 43200;

    private const CACHE_KEY = 'dh_github_updater_update_status_v1';
    private const CACHE_TTL = 7 * 86400;
    // „Nach Updates suchen“ höchstens so oft wirklich bei GitHub nachfragen (API-Kontingent)
    private const MIN_REFRESH_INTERVAL = 60;

    /**
     * @param EntityRepository<\Shopware\Core\Framework\Plugin\PluginCollection> $pluginRepository
     * @param EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>>|null $notificationRepository
     */
    public function __construct(
        private readonly GithubPluginManager $manager,
        private readonly SourceLinkStore $sourceLinkStore,
        private readonly UpdaterConfig $config,
        private readonly CacheItemPoolInterface $cache,
        private readonly EntityRepository $pluginRepository,
        private readonly ?EntityRepository $notificationRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Letztes Prüfergebnis; prüft neu, wenn erzwungen oder (bei aktiver
     * automatischer Prüfung) das Ergebnis veraltet ist.
     *
     * @return array{checkedAt: ?int, autoCheck: bool, plugins: array<string, array<string, mixed>>}
     */
    public function status(bool $refresh, Context $context): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        $cached = $item->isHit() && \is_array($item->get()) ? $item->get() : null;
        $autoCheck = $this->config->isAutoCheckEnabled();

        $stale = $cached === null || (time() - (int) ($cached['checkedAt'] ?? 0)) >= self::INTERVAL;
        $recent = $cached !== null && (time() - (int) ($cached['checkedAt'] ?? 0)) < self::MIN_REFRESH_INTERVAL;
        if (($refresh && !$recent) || ($autoCheck && $stale)) {
            $cached = $this->run($context, $autoCheck && $this->config->isUpdateNotificationEnabled());
        }

        return [
            'checkedAt' => $cached !== null ? (int) $cached['checkedAt'] : null,
            'autoCheck' => $autoCheck,
            'plugins' => $cached !== null ? $this->withoutOutdatedEntries($cached['plugins'], $context) : [],
        ];
    }

    /**
     * @return array{checkedAt: int, plugins: array<string, array<string, mixed>>}
     */
    public function run(Context $context, bool $notify): array
    {
        try {
            $this->manager->cleanupPendingUpdates($context);
        } catch (\Throwable $e) {
            $this->logger->warning('DhGithubUpdater: Aufbewahrte Sicherungen konnten nicht aufgeräumt werden.', ['error' => $e->getMessage()]);
        }

        $plugins = [];
        foreach ($this->sourceLinkStore->all() as $link) {
            $name = $link['pluginName'];
            try {
                $result = $this->manager->check($name, $context);
                $plugins[$name] = [
                    'repository' => $result['repository'],
                    'installedVersion' => $result['installedVersion'],
                    'installedCommit' => $result['installedCommit'],
                    'latestVersion' => $result['latestVersion'],
                    'updates' => $result['updates'],
                ];
            } catch (\Throwable $e) {
                if (!$e instanceof GithubUpdaterException) {
                    $this->logger->error('DhGithubUpdater: Update-Prüfung fehlgeschlagen.', ['plugin' => $name, 'exception' => $e]);
                }
                $plugins[$name] = [
                    'repository' => $link['repository'],
                    'installedCommit' => $link['installedCommit'],
                    'error' => $e instanceof GithubUpdaterException ? $e->getMessage() : 'Interner Fehler bei der Update-Prüfung. Details stehen im Shopware-Log (var/log).',
                    'updates' => [],
                ];
            }
        }

        $status = ['checkedAt' => time(), 'plugins' => $plugins];

        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set($status);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        if ($notify) {
            $this->notify($plugins, $context);
        }

        return $status;
    }

    /**
     * Ergebnisse verwerfen, die nach einem Update, Umverknüpfen oder Entfernen nicht mehr stimmen.
     *
     * @param array<string, array<string, mixed>> $plugins
     *
     * @return array<string, array<string, mixed>>
     */
    private function withoutOutdatedEntries(array $plugins, Context $context): array
    {
        $links = [];
        foreach ($this->sourceLinkStore->all() as $link) {
            $links[$link['pluginName']] = $link;
        }

        $versions = $this->installedVersions(array_keys($plugins), $context);

        return array_filter($plugins, static function (array $entry, string $name) use ($links, $versions): bool {
            $link = $links[$name] ?? null;

            return $link !== null
                && strcasecmp((string) $entry['repository'], $link['repository']) === 0
                && ($entry['installedCommit'] ?? null) === $link['installedCommit']
                && (!isset($entry['installedVersion']) || ($versions[$name] ?? null) === $entry['installedVersion']);
        }, \ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    private function installedVersions(array $names, Context $context): array
    {
        if ($names === []) {
            return [];
        }

        $versions = [];
        $criteria = (new Criteria())->addFilter(new EqualsAnyFilter('name', $names));
        foreach ($this->pluginRepository->search($criteria, $context)->getEntities() as $plugin) {
            /** @var PluginEntity $plugin */
            $versions[$plugin->getName()] = $plugin->getVersion();
        }

        return $versions;
    }

    /**
     * Meldet jede neue Version nur einmal.
     *
     * @param array<string, array<string, mixed>> $plugins
     */
    private function notify(array $plugins, Context $context): void
    {
        if ($this->notificationRepository === null) {
            return;
        }

        $notified = $this->config->getNotifiedVersions();
        $changed = false;

        foreach ($plugins as $name => $entry) {
            $latest = $entry['latestVersion'] ?? null;
            if (!\is_string($latest) || ($entry['updates'] ?? []) === [] || ($notified[$name] ?? null) === $latest) {
                continue;
            }

            try {
                $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($name, $entry, $latest): void {
                    $this->notificationRepository?->create([[
                        'id' => Uuid::randomHex(),
                        'status' => 'info',
                        'message' => \sprintf(
                            'GitHub-Updater: Für „%s“ ist Version %s verfügbar (installiert: %s). Aktualisieren unter Erweiterungen → Meine Erweiterungen.',
                            $name,
                            $latest,
                            (string) ($entry['installedVersion'] ?? '?'),
                        ),
                        'adminOnly' => true,
                        'requiredPrivileges' => [],
                    ]], $context);
                });
                $notified[$name] = $latest;
                $changed = true;
            } catch (\Throwable $e) {
                $this->logger->warning('DhGithubUpdater: Benachrichtigung konnte nicht angelegt werden.', ['error' => $e->getMessage()]);
            }
        }

        if ($changed) {
            $this->config->setNotifiedVersions($notified);
        }
    }
}

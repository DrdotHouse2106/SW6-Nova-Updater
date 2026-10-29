<?php declare(strict_types=1);

namespace DhGithubUpdater\Service;

use DhGithubUpdater\Config\UpdaterConfig;
use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Github\GitRef;
use DhGithubUpdater\Github\GithubApiClient;
use DhGithubUpdater\Github\ReleaseAsset;
use DhGithubUpdater\Github\RepositoryReference;
use DhGithubUpdater\Package\CompatibilityChecker;
use DhGithubUpdater\Package\PluginManifest;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Ermittelt installierbare (= zur Shopware-Version kompatible) Tags und Branches.
 *
 * Die Auswahl des Benutzers wird niemals ungeprüft übernommen: Jede Referenz
 * wird erneut gegen die Liste der Tags/Branches von GitHub aufgelöst und
 * auf einen festen Commit (SHA) festgelegt.
 */
class VersionResolver
{
    private const MAX_BRANCHES_TO_CHECK = 10;
    private const CACHE_TTL = 86400;

    public function __construct(
        private readonly GithubApiClient $client,
        private readonly CompatibilityChecker $compatibilityChecker,
        private readonly UpdaterConfig $config,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return list<VersionCandidate> neueste zuerst
     */
    public function listCompatible(RepositoryReference $repository, ?string $newerThan = null): array
    {
        $token = $this->config->getToken();
        $candidates = [];
        $releaseAssets = null;

        foreach ($this->selectTags($repository, $token, $newerThan) as $tag) {
            $releaseAssets ??= $this->loadReleaseAssets($repository, $token);
            $candidate = $this->buildCandidate($repository, $tag, $token, $releaseAssets[$tag->name] ?? []);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        if ($this->config->isBranchInstallAllowed()) {
            foreach ($this->selectBranches($repository, $token) as $branch) {
                $candidate = $this->buildCandidate($repository, $branch, $token);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    public function resolve(RepositoryReference $repository, string $refName, string $refType): VersionCandidate
    {
        if (!GitRef::isValidName($refName)) {
            throw new GithubUpdaterException('Ungültige Version ausgewählt.');
        }

        $token = $this->config->getToken();

        $refs = match ($refType) {
            GitRef::TYPE_TAG => $this->client->listTags($repository, $token),
            GitRef::TYPE_BRANCH => $this->config->isBranchInstallAllowed()
                ? $this->client->listBranches($repository, $token)
                : throw new GithubUpdaterException('Die Installation aus Branches ist in der Plugin-Konfiguration deaktiviert.', 403),
            default => throw new GithubUpdaterException('Ungültiger Versionstyp.'),
        };

        foreach ($refs as $ref) {
            // Wie in der Versionsliste: nur Tags mit lesbarer Versionsnummer (sonst greift der Downgrade-Schutz nicht)
            if ($ref->name !== $refName || ($refType === GitRef::TYPE_TAG && !CompatibilityChecker::isParsableVersion($ref->name))) {
                continue;
            }

            $manifest = $this->loadManifest($repository, $ref->commitSha, $token);
            if ($manifest === null) {
                throw new GithubUpdaterException('In dieser Version wurde keine gültige composer.json gefunden.', 422);
            }

            if (!$this->compatibilityChecker->isCompatible($manifest)) {
                throw new GithubUpdaterException(\sprintf(
                    'Diese Version ist nicht mit Shopware %s kompatibel (benötigt: %s).',
                    $this->compatibilityChecker->getShopwareVersion(),
                    $manifest->shopwareConstraint ?? 'keine Angabe',
                ), 422);
            }

            $assets = $refType === GitRef::TYPE_TAG ? ($this->loadReleaseAssets($repository, $token)[$ref->name] ?? []) : [];

            return new VersionCandidate($ref, $manifest, $this->selectAsset($assets, $manifest));
        }

        throw new GithubUpdaterException('Die ausgewählte Version existiert im Repository nicht (mehr).', 404);
    }

    /**
     * Mit $newerThan (Update-Prüfung) werden nur neuere Tags geprüft, dazu der neueste ältere
     * bzw. gleiche Tag als Bezugspunkt. Das spart GitHub-Anfragen: ohne Token erlaubt GitHub
     * nur 60 pro Stunde, und jeder geprüfte Tag kostet eine.
     *
     * @return list<GitRef>
     */
    private function selectTags(RepositoryReference $repository, ?string $token, ?string $newerThan = null): array
    {
        $tags = array_values(array_filter(
            $this->client->listTags($repository, $token),
            static fn (GitRef $tag): bool => CompatibilityChecker::isParsableVersion($tag->name),
        ));

        usort($tags, static fn (GitRef $a, GitRef $b): int => CompatibilityChecker::compare($b->name, $a->name) ?? 0);

        if ($newerThan !== null && CompatibilityChecker::isParsableVersion($newerThan)) {
            $selected = [];
            foreach ($tags as $tag) {
                $selected[] = $tag;
                if ((CompatibilityChecker::compare($tag->name, $newerThan) ?? 1) <= 0) {
                    break;
                }
            }
            $tags = $selected;
        }

        return \array_slice($tags, 0, $this->config->getMaxTagsToCheck());
    }

    /**
     * Berücksichtigt den Standard-Branch sowie Branches, deren Name die
     * aktuelle Shopware-Hauptversion enthält (z. B. "6.6" oder "release/6.6").
     *
     * @return list<GitRef>
     */
    private function selectBranches(RepositoryReference $repository, ?string $token): array
    {
        $defaultBranch = $this->client->getDefaultBranch($repository, $token);
        $parts = explode('.', $this->compatibilityChecker->getShopwareVersion());
        $majorMinor = $parts[0] . '.' . ($parts[1] ?? '');

        $selected = [];
        foreach ($this->client->listBranches($repository, $token) as $branch) {
            if ($branch->name === $defaultBranch || str_contains($branch->name, $majorMinor)) {
                $selected[] = $branch;
            }
        }

        usort($selected, static fn (GitRef $a, GitRef $b): int => ($b->name === $defaultBranch) <=> ($a->name === $defaultBranch));

        return \array_slice($selected, 0, self::MAX_BRANCHES_TO_CHECK);
    }

    /**
     * @param list<ReleaseAsset> $assets
     */
    private function buildCandidate(RepositoryReference $repository, GitRef $ref, ?string $token, array $assets = []): ?VersionCandidate
    {
        try {
            $manifest = $this->loadManifest($repository, $ref->commitSha, $token);
        } catch (GithubUpdaterException $e) {
            if ($e->getStatusCode() === 422) {
                return null;
            }

            throw $e;
        }

        if ($manifest === null || !$this->compatibilityChecker->isCompatible($manifest)) {
            return null;
        }

        return new VersionCandidate($ref, $manifest, $this->selectAsset($assets, $manifest));
    }

    /**
     * Release-Anhänge sind optional: Fehler beim Abruf führen nur dazu,
     * dass der Quellcode des Tags verwendet wird.
     *
     * @return array<string, list<ReleaseAsset>>
     */
    private function loadReleaseAssets(RepositoryReference $repository, ?string $token): array
    {
        try {
            return $this->client->listReleaseAssets($repository, $token);
        } catch (GithubUpdaterException) {
            return [];
        }
    }

    /**
     * @param list<ReleaseAsset> $assets
     */
    private function selectAsset(array $assets, PluginManifest $manifest): ?ReleaseAsset
    {
        $asset = ReleaseAsset::select($assets, $manifest->pluginName);

        return $asset !== null && $asset->size <= $this->config->getMaxDownloadBytes() ? $asset : null;
    }

    private function loadManifest(RepositoryReference $repository, string $commitSha, ?string $token): ?PluginManifest
    {
        // Inhalte eines Commits sind unveränderlich und können daher gefahrlos gecacht werden.
        $item = $this->cache->getItem('dh_github_updater_composer_' . hash('sha256', strtolower($repository->getFullName()) . '@' . $commitSha));

        if ($item->isHit()) {
            $json = $item->get();
        } else {
            $json = $this->client->getComposerJson($repository, $commitSha, $token) ?? '';
            $item->set($json);
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);
        }

        if (!\is_string($json) || $json === '') {
            return null;
        }

        return PluginManifest::fromJson($json);
    }
}

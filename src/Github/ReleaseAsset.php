<?php declare(strict_types=1);

namespace DhGithubUpdater\Github;

/**
 * Ein ZIP-Anhang eines GitHub-Releases (z. B. das fertig gebaute Plugin-Paket des Herstellers).
 */
final class ReleaseAsset
{
    private const NAME_PATTERN = '/^[A-Za-z0-9._+-]{1,200}\.zip$/i';

    /** Begleitdatei „<ZIP-Name>.sha256“ (Ausgabe von sha256sum), vom Release-Workflow mit veröffentlicht */
    public const CHECKSUM_SUFFIX = '.sha256';
    public const MAX_CHECKSUM_BYTES = 4096;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $size,
        // SHA-256 des Anhangs laut GitHub (hex); null bei älteren Releases ohne Angabe
        public readonly ?string $sha256 = null,
        // Anhang-ID der Begleitdatei „<name>.sha256“ im selben Release; null = keine vorhanden
        public readonly ?int $checksumAssetId = null,
    ) {
    }

    public function withChecksumAsset(?int $checksumAssetId): self
    {
        return new self($this->id, $this->name, $this->size, $this->sha256, $checksumAssetId);
    }

    /** Lässt sich das Paket nach dem Download prüfen (Digest von GitHub und/oder .sha256-Datei)? */
    public function isVerifiable(): bool
    {
        return $this->sha256 !== null || $this->checksumAssetId !== null;
    }

    /**
     * Begleitdatei „<Paket>.zip.sha256“ aus den API-Daten eines Anhangs: [Name in Kleinbuchstaben ohne .sha256, ID]
     *
     * @param array<mixed> $data
     *
     * @return array{0: string, 1: int}|null
     */
    public static function checksumFromApi(array $data): ?array
    {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        $size = $data['size'] ?? null;
        $state = $data['state'] ?? 'uploaded';

        if (!\is_int($id) || $id <= 0 || !\is_string($name) || !\is_int($size) || $size > self::MAX_CHECKSUM_BYTES || $state !== 'uploaded'
            || !str_ends_with(strtolower($name), self::CHECKSUM_SUFFIX)) {
            return null;
        }

        $zipName = substr($name, 0, -\strlen(self::CHECKSUM_SUFFIX));

        return preg_match(self::NAME_PATTERN, $zipName) ? [strtolower($zipName), $id] : null;
    }

    /**
     * Erwarteter Hash aus einer .sha256-Datei („<64 hex>  <Dateiname>“ wie von sha256sum, oder nur der Hash).
     * Steht ein Dateiname darin, muss er zum Paket passen.
     */
    public static function parseChecksumFile(string $content, string $zipName): ?string
    {
        $content = trim($content);
        if (!preg_match('/^([0-9a-fA-F]{64})(?:[ \t]+\*?([^\r\n]+))?$/', $content, $match)) {
            return null;
        }

        $file = isset($match[2]) ? trim($match[2]) : '';
        if ($file !== '' && basename($file) !== $zipName) {
            return null;
        }

        return strtolower($match[1]);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromApi(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? null;
        $size = $data['size'] ?? null;
        $state = $data['state'] ?? 'uploaded';

        if (!\is_int($id) || $id <= 0 || !\is_string($name) || !preg_match(self::NAME_PATTERN, $name) || !\is_int($size) || $state !== 'uploaded') {
            return null;
        }

        $digest = $data['digest'] ?? null;
        $sha256 = \is_string($digest) && preg_match('/^sha256:([0-9a-f]{64})$/i', $digest, $match) ? strtolower($match[1]) : null;

        return new self($id, $name, $size, $sha256);
    }

    /**
     * Wählt das Plugin-Paket aus den Anhängen eines Releases:
     * 1. exakt "<PluginName>.zip", 2. beginnt mit dem Plugin-Namen, 3. einziger ZIP-Anhang.
     *
     * @param list<self> $assets
     */
    public static function select(array $assets, string $pluginName): ?self
    {
        $lower = strtolower($pluginName);

        foreach ($assets as $asset) {
            if (strtolower($asset->name) === $lower . '.zip') {
                return $asset;
            }
        }

        foreach ($assets as $asset) {
            if (str_starts_with(strtolower($asset->name), $lower)) {
                return $asset;
            }
        }

        return \count($assets) === 1 ? $assets[0] : null;
    }
}

<?php declare(strict_types=1);

namespace DhGithubUpdater\Package;

use DhGithubUpdater\Exception\GithubUpdaterException;

/**
 * Entpackt GitHub-Archive sicher.
 *
 * Schutzmaßnahmen:
 *  - Zip-Slip: absolute Pfade, "..", Backslashes, Laufwerksbuchstaben und
 *    Steuerzeichen werden abgelehnt.
 *  - Symbolische Links werden abgelehnt.
 *  - Zip-Bomben: Anzahl der Dateien und Gesamtgröße (tatsächlich geschriebene
 *    Bytes, nicht nur die Angaben im Archiv) sind begrenzt.
 *  - Das GitHub-typische Wurzelverzeichnis ("owner-repo-sha/") wird entfernt,
 *    der Inhalt landet direkt im (neu angelegten, leeren) Zielverzeichnis.
 */
class SafeZipExtractor
{
    private const S_IFMT = 0170000;
    private const S_IFLNK = 0120000;
    private const S_IFDIR = 0040000;
    private const S_IFREG = 0100000;

    public function __construct(
        private readonly int $maxFiles = 20000,
        private readonly int $maxTotalBytes = 300 * 1024 * 1024,
    ) {
    }

    public function extract(string $zipFile, string $targetDir): void
    {
        if (!is_dir($targetDir) || (new \FilesystemIterator($targetDir))->valid()) {
            throw new GithubUpdaterException('Das Zielverzeichnis für das Entpacken muss leer sein.', 500);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::RDONLY) !== true) {
            throw new GithubUpdaterException('Das heruntergeladene Archiv ist kein gültiges ZIP.', 422);
        }

        try {
            $entries = $this->collectEntries($zip);
            $this->writeEntries($zip, $entries, $targetDir);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<array{index: int, path: string, dir: bool}>
     */
    private function collectEntries(\ZipArchive $zip): array
    {
        if ($zip->numFiles === 0 || $zip->numFiles > $this->maxFiles) {
            throw new GithubUpdaterException('Das Archiv ist leer oder enthält zu viele Dateien.', 422);
        }

        $root = null;
        $entries = [];
        $declaredSize = 0;

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new GithubUpdaterException('Das Archiv ist beschädigt.', 422);
            }

            $name = $stat['name'];
            $this->assertSafeName($name);

            $isDir = str_ends_with($name, '/');
            $mode = $this->getUnixMode($zip, $i);
            if ($mode !== null) {
                $type = $mode & self::S_IFMT;
                if ($type === self::S_IFLNK) {
                    throw new GithubUpdaterException(\sprintf('Das Archiv enthält einen symbolischen Link (%s). Aus Sicherheitsgründen wird es nicht installiert.', self::displayName($name)), 422);
                }
                if ($type !== 0 && $type !== self::S_IFDIR && $type !== self::S_IFREG) {
                    throw new GithubUpdaterException('Das Archiv enthält nicht unterstützte Dateitypen.', 422);
                }
            }

            $segments = explode('/', rtrim($name, '/'));
            $first = $segments[0];
            if ($root === null) {
                $root = $first;
            } elseif ($first !== $root) {
                throw new GithubUpdaterException('Unerwartete Archivstruktur (mehrere Wurzelverzeichnisse).', 422);
            }

            $relative = implode('/', \array_slice($segments, 1));
            if ($relative === '') {
                if (!$isDir) {
                    throw new GithubUpdaterException('Unerwartete Archivstruktur.', 422);
                }

                continue;
            }

            $declaredSize += max(0, (int) $stat['size']);
            if ($declaredSize > $this->maxTotalBytes) {
                throw new GithubUpdaterException('Der entpackte Inhalt wäre zu groß.', 422);
            }

            $entries[] = ['index' => $i, 'path' => $relative, 'dir' => $isDir];
        }

        return $entries;
    }

    /**
     * @param list<array{index: int, path: string, dir: bool}> $entries
     */
    private function writeEntries(\ZipArchive $zip, array $entries, string $targetDir): void
    {
        $base = rtrim($targetDir, '/');
        $written = 0;

        foreach ($entries as $entry) {
            $destination = $base . '/' . $entry['path'];

            if ($entry['dir']) {
                $this->ensureDirectory($destination);

                continue;
            }

            $this->ensureDirectory(\dirname($destination));

            $in = $zip->getStreamIndex($entry['index']);
            if ($in === false) {
                throw new GithubUpdaterException('Eine Datei im Archiv konnte nicht gelesen werden.', 422);
            }

            // "x" schlägt fehl, falls die Datei bereits existiert (z. B. doppelte Einträge).
            $out = @fopen($destination, 'xb');
            if ($out === false) {
                fclose($in);

                throw new GithubUpdaterException('Das Archiv enthält doppelte oder ungültige Einträge.', 422);
            }

            try {
                $remaining = $this->maxTotalBytes - $written;
                $copied = stream_copy_to_stream($in, $out, $remaining + 1);
                if ($copied === false) {
                    throw new GithubUpdaterException('Fehler beim Entpacken des Archivs.', 500);
                }
                $written += $copied;
                if ($written > $this->maxTotalBytes) {
                    throw new GithubUpdaterException('Der entpackte Inhalt wäre zu groß.', 422);
                }
            } finally {
                fclose($in);
                fclose($out);
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_link($directory)) {
            throw new GithubUpdaterException('Ungültiger Pfad im Archiv.', 422);
        }

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new GithubUpdaterException('Verzeichnis konnte beim Entpacken nicht angelegt werden.', 500);
        }
    }

    /**
     * Eintragsname für Fehlermeldungen: nur unverdächtige Zeichen, gekürzt (Meldungen werden als HTML angezeigt).
     */
    private static function displayName(string $name): string
    {
        $clean = (string) preg_replace('#[^A-Za-z0-9._/-]#', '_', $name);

        return \strlen($clean) > 120 ? substr($clean, 0, 117) . '...' : $clean;
    }

    private function assertSafeName(string $name): void
    {
        if ($name === ''
            || \strlen($name) > 1024
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $name)
            || preg_match('/^[A-Za-z]:/', $name)
        ) {
            throw new GithubUpdaterException('Das Archiv enthält ungültige Dateipfade.', 422);
        }

        foreach (explode('/', rtrim($name, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new GithubUpdaterException('Das Archiv enthält ungültige Dateipfade.', 422);
            }
        }
    }

    private function getUnixMode(\ZipArchive $zip, int $index): ?int
    {
        $opsys = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return null;
        }

        if ($opsys !== \ZipArchive::OPSYS_UNIX) {
            return null;
        }

        return ($attributes >> 16) & 0xFFFF;
    }
}

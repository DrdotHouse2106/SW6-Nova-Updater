<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Exception\ChecksumException;
use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Github\GithubApiClient;
use DhGithubUpdater\Github\ReleaseAsset;
use DhGithubUpdater\Github\RepositoryReference;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ReleaseAssetTest extends TestCase
{
    public function testSelectsAssetByPluginName(): void
    {
        $assets = [new ReleaseAsset(1, 'sources.zip', 10), new ReleaseAsset(2, 'SwagPayPal.zip', 10), new ReleaseAsset(3, 'SwagPayPal-10.0.0.zip', 10)];
        static::assertSame(2, ReleaseAsset::select($assets, 'SwagPayPal')?->id);

        $assets = [new ReleaseAsset(1, 'sources.zip', 10), new ReleaseAsset(3, 'swagpaypal-10.0.0.zip', 10)];
        static::assertSame(3, ReleaseAsset::select($assets, 'SwagPayPal')?->id);

        static::assertSame(1, ReleaseAsset::select([new ReleaseAsset(1, 'plugin.zip', 10)], 'SwagPayPal')?->id);
        static::assertNull(ReleaseAsset::select([new ReleaseAsset(1, 'a.zip', 10), new ReleaseAsset(2, 'b.zip', 10)], 'SwagPayPal'));
    }

    public function testRejectsInvalidApiData(): void
    {
        static::assertNull(ReleaseAsset::fromApi(['id' => 1, 'name' => '../evil.zip', 'size' => 1]));
        static::assertNull(ReleaseAsset::fromApi(['id' => 1, 'name' => 'plugin.tar.gz', 'size' => 1]));
        static::assertNull(ReleaseAsset::fromApi(['id' => 1, 'name' => 'plugin.zip', 'size' => 1, 'state' => 'starter']));
        static::assertNotNull(ReleaseAsset::fromApi(['id' => 1, 'name' => 'plugin.zip', 'size' => 1, 'state' => 'uploaded']));
    }

    public function testListsAssetsOfPublishedReleases(): void
    {
        $client = new GithubApiClient(new MockHttpClient(static fn () => new MockResponse((string) json_encode([
            ['tag_name' => 'v1.0.0', 'draft' => false, 'assets' => [['id' => 5, 'name' => 'MyPlugin.zip', 'size' => 100, 'state' => 'uploaded']]],
            ['tag_name' => 'v2.0.0', 'draft' => true, 'assets' => [['id' => 6, 'name' => 'MyPlugin.zip', 'size' => 100]]],
            ['tag_name' => 'v0.9.0', 'draft' => false, 'assets' => []],
        ]))));

        $releases = $client->listReleaseAssets(RepositoryReference::fromFullName('owner/repo'), null);

        static::assertSame(['v1.0.0'], array_keys($releases));
        static::assertSame(5, $releases['v1.0.0'][0]->id);
    }

    public function testDownloadsAssetViaApiAndFollowsRedirectWithoutToken(): void
    {
        $seen = [];
        $client = new GithubApiClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen[$url] = implode("\n", $options['headers'] ?? []);
            if (str_starts_with($url, 'https://api.github.com/')) {
                return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://release-assets.githubusercontent.com/asset/5']]);
            }

            return new MockResponse('ZIP');
        }));

        $target = (string) tempnam(sys_get_temp_dir(), 'dh-ghu-');
        $client->downloadReleaseAsset(RepositoryReference::fromFullName('owner/repo'), new ReleaseAsset(5, 'MyPlugin.zip', 3), $target, 1024, 'ghp_secrettoken1234567890');

        static::assertSame('ZIP', file_get_contents($target));
        unlink($target);
        static::assertStringContainsString('application/octet-stream', $seen['https://api.github.com/repos/owner/repo/releases/assets/5']);
        static::assertStringNotContainsString('ghp_secrettoken', $seen['https://release-assets.githubusercontent.com/asset/5']);
    }

    public function testReadsSha256DigestFromApi(): void
    {
        $hash = hash('sha256', 'ZIP');

        static::assertSame($hash, ReleaseAsset::fromApi(['id' => 1, 'name' => 'plugin.zip', 'size' => 3, 'digest' => 'sha256:' . strtoupper($hash)])?->sha256);
        static::assertNull(ReleaseAsset::fromApi(['id' => 1, 'name' => 'plugin.zip', 'size' => 3, 'digest' => 'md5:abc'])?->sha256);
        static::assertNull(ReleaseAsset::fromApi(['id' => 1, 'name' => 'plugin.zip', 'size' => 3])?->sha256);
    }

    public function testAcceptsAssetWithMatchingDigest(): void
    {
        $client = new GithubApiClient(new MockHttpClient(static fn () => new MockResponse('ZIP')));

        $target = (string) tempnam(sys_get_temp_dir(), 'dh-ghu-');
        $client->downloadReleaseAsset(RepositoryReference::fromFullName('owner/repo'), new ReleaseAsset(5, 'MyPlugin.zip', 3, hash('sha256', 'ZIP')), $target, 1024, null);

        static::assertSame('ZIP', file_get_contents($target));
        unlink($target);
    }

    public function testRejectsAssetWithForeignDigest(): void
    {
        $client = new GithubApiClient(new MockHttpClient(static fn () => new MockResponse('BAD')));

        $target = (string) tempnam(sys_get_temp_dir(), 'dh-ghu-');
        try {
            $client->downloadReleaseAsset(RepositoryReference::fromFullName('owner/repo'), new ReleaseAsset(5, 'MyPlugin.zip', 3, hash('sha256', 'ZIP')), $target, 1024, null);
            static::fail('Ein Paket mit abweichender Prüfsumme darf nicht akzeptiert werden.');
        } catch (GithubUpdaterException $e) {
            static::assertStringContainsString('Prüfsumme', $e->getMessage());
        } finally {
            unlink($target);
        }
    }

    public function testAssignsChecksumFileToPackage(): void
    {
        $client = new GithubApiClient(new MockHttpClient(static fn () => new MockResponse((string) json_encode([
            ['tag_name' => 'v1.0.0', 'draft' => false, 'assets' => [
                ['id' => 5, 'name' => 'MyPlugin-1.0.0.zip', 'size' => 100, 'state' => 'uploaded'],
                ['id' => 6, 'name' => 'MyPlugin-1.0.0.zip.sha256', 'size' => 85, 'state' => 'uploaded'],
                ['id' => 7, 'name' => 'Other.zip.sha256', 'size' => 85, 'state' => 'uploaded'],
            ]],
        ]))));

        $releases = $client->listReleaseAssets(RepositoryReference::fromFullName('owner/repo'), null);

        static::assertCount(1, $releases['v1.0.0']);
        static::assertSame(5, $releases['v1.0.0'][0]->id);
        static::assertSame(6, $releases['v1.0.0'][0]->checksumAssetId);
    }

    public function testParsesChecksumFile(): void
    {
        $hash = hash('sha256', 'ZIP');

        static::assertSame($hash, ReleaseAsset::parseChecksumFile($hash . '  MyPlugin.zip' . "\n", 'MyPlugin.zip'));
        static::assertSame($hash, ReleaseAsset::parseChecksumFile(strtoupper($hash) . ' *MyPlugin.zip', 'MyPlugin.zip'));
        static::assertSame($hash, ReleaseAsset::parseChecksumFile($hash, 'MyPlugin.zip'));
        static::assertNull(ReleaseAsset::parseChecksumFile($hash . '  Other.zip', 'MyPlugin.zip'));
        static::assertNull(ReleaseAsset::parseChecksumFile('abc  MyPlugin.zip', 'MyPlugin.zip'));
        static::assertNull(ReleaseAsset::parseChecksumFile($hash . "  MyPlugin.zip\n" . $hash . '  Other.zip', 'MyPlugin.zip'));
        static::assertNull(ReleaseAsset::checksumFromApi(['id' => 1, 'name' => 'MyPlugin.zip.sha256', 'size' => 99999]));
        static::assertNull(ReleaseAsset::checksumFromApi(['id' => 1, 'name' => '../x.zip.sha256', 'size' => 80]));
    }

    public function testVerifiesAgainstChecksumFile(): void
    {
        $hash = hash('sha256', 'ZIP');
        $target = (string) tempnam(sys_get_temp_dir(), 'dh-ghu-');
        $this->clientServing('ZIP', $hash . '  MyPlugin.zip')->downloadReleaseAsset(
            RepositoryReference::fromFullName('owner/repo'),
            new ReleaseAsset(5, 'MyPlugin.zip', 3, $hash, 6),
            $target,
            1024,
            null,
            true,
        );

        static::assertSame('ZIP', file_get_contents($target));
        unlink($target);
    }

    public function testRejectsPackageNotMatchingChecksumFile(): void
    {
        // Digest von GitHub passt zum (ausgetauschten) Paket, die unabhängig veröffentlichte .sha256 nicht
        $this->expectDownloadFailure(
            $this->clientServing('BAD', hash('sha256', 'ZIP') . '  MyPlugin.zip'),
            new ReleaseAsset(5, 'MyPlugin.zip', 3, hash('sha256', 'BAD'), 6),
            true,
            '.sha256',
        );
    }

    public function testRejectsInvalidChecksumFile(): void
    {
        $this->expectDownloadFailure($this->clientServing('ZIP', 'kaputt'), new ReleaseAsset(5, 'MyPlugin.zip', 3, null, 6), false, '.sha256');
    }

    public function testRequiresChecksumWhenConfigured(): void
    {
        $requests = 0;
        $client = new GithubApiClient(new MockHttpClient(static function () use (&$requests) {
            ++$requests;

            return new MockResponse('ZIP');
        }));

        $this->expectDownloadFailure($client, new ReleaseAsset(5, 'MyPlugin.zip', 3), true, 'nicht prüfen');
        static::assertSame(0, $requests, 'Ohne Prüfsumme darf das Paket gar nicht erst geladen werden.');
    }

    public function testUnverifiedPackageAllowedWhenNotRequired(): void
    {
        $target = (string) tempnam(sys_get_temp_dir(), 'dh-ghu-');
        $this->clientServing('ZIP', '')->downloadReleaseAsset(RepositoryReference::fromFullName('owner/repo'), new ReleaseAsset(5, 'MyPlugin.zip', 3), $target, 1024, null, false);

        static::assertSame('ZIP', file_get_contents($target));
        unlink($target);
    }

    private function clientServing(string $zip, string $checksumFile): GithubApiClient
    {
        return new GithubApiClient(new MockHttpClient(static fn (string $method, string $url) => new MockResponse(
            str_ends_with($url, '/releases/assets/6') ? $checksumFile : $zip,
        )));
    }

    private function expectDownloadFailure(GithubApiClient $client, ReleaseAsset $asset, bool $require, string $messagePart): void
    {
        $target = (string) tempnam(sys_get_temp_dir(), 'dh-ghu-');
        try {
            $client->downloadReleaseAsset(RepositoryReference::fromFullName('owner/repo'), $asset, $target, 1024, null, $require);
            static::fail('Das Paket darf nicht akzeptiert werden.');
        } catch (ChecksumException $e) {
            static::assertStringContainsString($messagePart, $e->getMessage());
        } finally {
            @unlink($target);
        }
    }
}

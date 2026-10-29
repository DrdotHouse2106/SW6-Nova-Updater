<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Catalog\CatalogEntry;
use DhGithubUpdater\Github\RepositoryReference;
use DhGithubUpdater\Security\RepositoryAllowList;
use PHPUnit\Framework\TestCase;

class CatalogEntryTest extends TestCase
{
    public function testRenamesOnlyWithinSameOwner(): void
    {
        $entry = $this->entry('Vendor/New-Name', ['Vendor/Old-Name']);

        static::assertTrue($entry->canRenameFrom('vendor/old-name', new RepositoryAllowList('')));
        static::assertFalse($entry->canRenameFrom('Vendor/Unrelated', new RepositoryAllowList('')));
    }

    public function testNeverMovesLinkToAnotherOwner(): void
    {
        // Ein manipulierter Katalog könnte ein fremdes Repository als „neuen Namen“ ausgeben
        $entry = $this->entry('attacker/FroshTools', ['FriendsOfShopware/FroshTools']);

        static::assertTrue($entry->isFormerRepository('FriendsOfShopware/FroshTools'));
        static::assertFalse($entry->canRenameFrom('FriendsOfShopware/FroshTools', new RepositoryAllowList('')));
    }

    public function testRespectsAllowList(): void
    {
        $entry = $this->entry('Vendor/New-Name', ['Vendor/Old-Name']);

        static::assertFalse($entry->canRenameFrom('Vendor/Old-Name', new RepositoryAllowList('Vendor/Old-Name')));
        static::assertTrue($entry->canRenameFrom('Vendor/Old-Name', new RepositoryAllowList('vendor/*')));
    }

    /**
     * @param list<string> $former
     */
    private function entry(string $repository, array $former): CatalogEntry
    {
        return new CatalogEntry(
            RepositoryReference::fromFullName($repository),
            'MyPlugin',
            'My Plugin',
            'Vendor',
            'other',
            'MIT',
            ['de-DE' => 'Test', 'en-GB' => 'Test'],
            array_map(static fn (string $name): RepositoryReference => RepositoryReference::fromFullName($name), $former),
        );
    }
}

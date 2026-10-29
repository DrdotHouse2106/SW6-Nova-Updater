<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Config\DefaultAllowList;
use DhGithubUpdater\Github\RepositoryReference;
use DhGithubUpdater\Security\RepositoryAllowList;
use PHPUnit\Framework\TestCase;

class DefaultAllowListTest extends TestCase
{
    public function testDefaultsToKnownOwners(): void
    {
        static::assertSame("DrdotHouse2106/*\nFriendsOfShopware/*", DefaultAllowList::build([]));
    }

    public function testKeepsAlreadyLinkedRepositoriesOfOtherOwners(): void
    {
        $raw = DefaultAllowList::build(['DrdotHouse2106/SW6-Githubupdater', 'shopware/SwagPayPal', 'shopware/SwagPayPal', 'kein gültiger Name']);
        $allowList = new RepositoryAllowList($raw);

        static::assertSame("DrdotHouse2106/*\nFriendsOfShopware/*\nshopware/SwagPayPal", $raw);
        static::assertTrue($allowList->isRestricted());
        static::assertTrue($allowList->isAllowed(RepositoryReference::fromFullName('FriendsOfShopware/FroshTools')));
        static::assertTrue($allowList->isAllowed(RepositoryReference::fromFullName('shopware/SwagPayPal')));
        static::assertFalse($allowList->isAllowed(RepositoryReference::fromFullName('shopware/SwagLanguagePack')));
        static::assertFalse($allowList->isAllowed(RepositoryReference::fromFullName('attacker/FroshTools')));
    }
}

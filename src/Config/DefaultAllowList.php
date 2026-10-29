<?php declare(strict_types=1);

namespace DhGithubUpdater\Config;

use DhGithubUpdater\Github\RepositoryReference;
use DhGithubUpdater\Security\RepositoryAllowList;

/**
 * Vorbelegung der Liste erlaubter Repositories, solange sie leer ist (leer = alle erlaubt).
 * Bereits verknüpfte Repositories anderer Besitzer werden zusätzlich eingetragen,
 * damit deren Updates weiter funktionieren.
 */
final class DefaultAllowList
{
    public const OWNERS = ['DrdotHouse2106/*', 'FriendsOfShopware/*'];

    /**
     * @param list<string> $linkedRepositories
     */
    public static function build(array $linkedRepositories): string
    {
        $entries = self::OWNERS;
        $allowList = new RepositoryAllowList(implode("\n", $entries));

        foreach ($linkedRepositories as $fullName) {
            try {
                $repository = RepositoryReference::fromFullName($fullName);
            } catch (\Throwable) {
                continue;
            }

            if (!$allowList->isAllowed($repository)) {
                $entries[] = $repository->getFullName();
                $allowList = new RepositoryAllowList(implode("\n", $entries));
            }
        }

        return implode("\n", $entries);
    }
}

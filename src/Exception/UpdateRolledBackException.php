<?php declare(strict_types=1);

namespace DhGithubUpdater\Exception;

/**
 * Das Shopware-Update ist gescheitert, der vorherige Plugin-Stand wurde wiederhergestellt.
 */
class UpdateRolledBackException extends GithubUpdaterException
{
}

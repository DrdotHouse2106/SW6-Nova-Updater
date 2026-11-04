<?php declare(strict_types=1);

namespace DhGithubUpdater\Exception;

/**
 * Das Release-Paket ließ sich nicht prüfen oder seine Prüfsumme stimmt nicht. In diesem Fall wird nicht auf den
 * Quellcode des Tags ausgewichen, sondern das Update abgebrochen – ein abweichendes Paket ist ein Warnsignal.
 */
class ChecksumException extends GithubUpdaterException
{
}

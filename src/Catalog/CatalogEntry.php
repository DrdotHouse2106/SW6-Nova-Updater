<?php declare(strict_types=1);

namespace DhGithubUpdater\Catalog;

use DhGithubUpdater\Github\RepositoryReference;
use DhGithubUpdater\Security\RepositoryAllowList;

/**
 * Ein Eintrag im Plugin-Katalog.
 */
final class CatalogEntry
{
    /**
     * @param array<string, string> $description Sprache (z. B. "de-DE") => Text
     * @param list<RepositoryReference> $formerRepositories frühere Namen des Repositorys (nach Umbenennung auf GitHub)
     */
    public function __construct(
        public readonly RepositoryReference $repository,
        public readonly string $pluginName,
        public readonly string $name,
        public readonly string $vendor,
        public readonly string $category,
        public readonly ?string $license,
        public readonly array $description,
        public readonly array $formerRepositories = [],
    ) {
    }

    /**
     * @return array{repository: string, repositoryUrl: string, pluginName: string, name: string, vendor: string, category: string, license: ?string, description: array<string, string>, formerRepositories: list<string>}
     */
    public function toArray(): array
    {
        return [
            'repository' => $this->repository->getFullName(),
            'repositoryUrl' => $this->repository->getUrl(),
            'pluginName' => $this->pluginName,
            'name' => $this->name,
            'vendor' => $this->vendor,
            'category' => $this->category,
            'license' => $this->license,
            'description' => $this->description,
            'formerRepositories' => array_map(static fn (RepositoryReference $repository): string => $repository->getFullName(), $this->formerRepositories),
        ];
    }

    /** Gehört das (ggf. alte) Repository zu diesem Eintrag? */
    public function isFormerRepository(string $fullName): bool
    {
        foreach ($this->formerRepositories as $repository) {
            if (strtolower($repository->getFullName()) === strtolower($fullName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Darf eine Verknüpfung mit $linkedRepository automatisch auf diesen Eintrag umgestellt werden?
     * Nur bei einem früheren Namen desselben Besitzers, und nur wenn das neue Repository erlaubt ist.
     */
    public function canRenameFrom(string $linkedRepository, RepositoryAllowList $allowList): bool
    {
        if (!$this->isFormerRepository($linkedRepository)) {
            return false;
        }

        $owner = strtolower(explode('/', $linkedRepository)[0]);

        return $owner === strtolower($this->repository->owner) && $allowList->isAllowed($this->repository);
    }
}

<?php declare(strict_types=1);

namespace DhGithubUpdater\Controller;

use DhGithubUpdater\Config\UpdaterConfig;
use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Exception\UpdateRolledBackException;
use DhGithubUpdater\Package\PackageInspector;
use DhGithubUpdater\Service\GithubPluginManager;
use DhGithubUpdater\Service\StepwiseLifecycle;
use DhGithubUpdater\Service\UpdateChecker;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API-Endpunkte des GitHub-Updaters.
 *
 * Zugriffsschutz in mehreren Stufen:
 *  1. Nur Admin-API (`_routeScope: api`) mit gültigem OAuth-Token.
 *  2. Shopware-ACL: "system.plugin_maintain", für Code-Änderungen zusätzlich "system.plugin_upload".
 *  3. Nur echte Admin-Benutzer (keine Integrationen); optional nur Administratoren.
 *  4. Abgeschaltet, wenn die Laufzeit-Erweiterungsverwaltung deaktiviert ist.
 */
#[Route(defaults: ['_routeScope' => ['api'], '_acl' => ['system.plugin_maintain']])]
class GithubUpdaterController
{
    public function __construct(
        private readonly GithubPluginManager $manager,
        private readonly StepwiseLifecycle $stepwise,
        private readonly UpdateChecker $updateChecker,
        private readonly PackageInspector $packageInspector,
        private readonly UpdaterConfig $config,
        private readonly LoggerInterface $logger,
        private readonly bool $runtimeExtensionManagement,
    ) {
    }

    #[Route(path: '/api/_action/dh-github-updater/versions', name: 'api.action.dh_github_updater.versions', methods: ['GET'])]
    public function versions(Request $request, Context $context): JsonResponse
    {
        return $this->handle($context, false, fn (): array => $this->manager->listVersions(
            $request->query->getString('repository'),
        ));
    }

    /**
     * Prüft ein Repository, ohne etwas herunterzuladen oder zu installieren.
     */
    #[Route(path: '/api/_action/dh-github-updater/test-connection', name: 'api.action.dh_github_updater.test_connection', methods: ['GET'])]
    public function testConnection(Request $request, Context $context): JsonResponse
    {
        $pluginName = $request->query->getString('pluginName');

        return $this->handle($context, false, fn (): array => $this->manager->testConnection(
            $request->query->getString('repository'),
            $pluginName !== '' ? $pluginName : null,
            $context,
        ));
    }

    #[Route(path: '/api/_action/dh-github-updater/catalog', name: 'api.action.dh_github_updater.catalog', methods: ['GET'])]
    public function catalog(Request $request, Context $context): JsonResponse
    {
        return $this->handle($context, false, fn (): array => $this->manager->catalog(
            $request->query->getBoolean('refresh'),
            $context,
        ));
    }

    /**
     * Ergebnis der automatischen Update-Prüfung (prüft neu, wenn veraltet oder refresh=1).
     */
    #[Route(path: '/api/_action/dh-github-updater/update-status', name: 'api.action.dh_github_updater.update_status', methods: ['GET'])]
    public function updateStatus(Request $request, Context $context): JsonResponse
    {
        return $this->handle($context, false, fn (): array => $this->updateChecker->status(
            $request->query->getBoolean('refresh'),
            $context,
        ));
    }

    /**
     * Prüft nach einer Installation, ob benötigte Composer-Pakete inzwischen vorhanden sind.
     */
    #[Route(path: '/api/_action/dh-github-updater/missing-packages', name: 'api.action.dh_github_updater.missing_packages', methods: ['GET'])]
    public function missingPackages(Request $request, Context $context): JsonResponse
    {
        return $this->handle($context, false, function () use ($request): array {
            $packages = array_values(array_filter(
                array_map('trim', explode(',', $request->query->getString('packages'))),
                static fn (string $package): bool => preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $package) === 1
            ));

            return ['missing' => $this->packageInspector->missingPackages(\array_slice($packages, 0, 50))];
        });
    }

    #[Route(path: '/api/_action/dh-github-updater/sources', name: 'api.action.dh_github_updater.sources', methods: ['GET'])]
    public function sources(Context $context): JsonResponse
    {
        return $this->handle($context, false, fn (): array => ['sources' => $this->manager->sources()]);
    }

    #[Route(path: '/api/_action/dh-github-updater/check/{pluginName}', name: 'api.action.dh_github_updater.check', requirements: ['pluginName' => '[A-Za-z][A-Za-z0-9_]{0,99}'], methods: ['GET'])]
    public function check(string $pluginName, Context $context): JsonResponse
    {
        return $this->handle($context, false, fn (): array => $this->manager->check($pluginName, $context));
    }

    /**
     * Release-Notizen („Was ist neu?“) – vor einem Update oder danach.
     */
    #[Route(path: '/api/_action/dh-github-updater/changelog/{pluginName}', name: 'api.action.dh_github_updater.changelog', requirements: ['pluginName' => '[A-Za-z][A-Za-z0-9_]{0,99}'], methods: ['GET'])]
    public function changelog(string $pluginName, Request $request, Context $context): JsonResponse
    {
        return $this->handle($context, false, fn (): array => $this->manager->changelog(
            $pluginName,
            $request->query->has('from') ? $request->query->getString('from') : null,
            $request->query->has('to') ? $request->query->getString('to') : null,
            $context,
        ));
    }

    #[Route(path: '/api/_action/dh-github-updater/install', name: 'api.action.dh_github_updater.install', defaults: ['_acl' => ['system.plugin_maintain', 'system.plugin_upload']], methods: ['POST'])]
    public function install(Request $request, Context $context): JsonResponse
    {
        return $this->handle($context, true, fn (): array => $this->manager->install(
            $this->getString($request, 'repository'),
            $this->getString($request, 'ref'),
            $this->getString($request, 'type'),
            $context,
        ));
    }

    #[Route(path: '/api/_action/dh-github-updater/update/{pluginName}', name: 'api.action.dh_github_updater.update', requirements: ['pluginName' => '[A-Za-z][A-Za-z0-9_]{0,99}'], defaults: ['_acl' => ['system.plugin_maintain', 'system.plugin_upload']], methods: ['POST'])]
    public function update(string $pluginName, Request $request, Context $context): JsonResponse
    {
        return $this->handle($context, true, fn (): array => $this->manager->update(
            $pluginName,
            $this->getString($request, 'ref'),
            $this->getString($request, 'type'),
            $context,
        ));
    }

    #[Route(path: '/api/_action/dh-github-updater/link/{pluginName}', name: 'api.action.dh_github_updater.link', requirements: ['pluginName' => '[A-Za-z][A-Za-z0-9_]{0,99}'], defaults: ['_acl' => ['system.plugin_maintain', 'system.plugin_upload']], methods: ['POST'])]
    public function link(string $pluginName, Request $request, Context $context): JsonResponse
    {
        return $this->handle($context, true, fn (): array => [
            'repository' => $this->manager->link($pluginName, $this->getString($request, 'repository'), $context),
        ]);
    }

    /**
     * Schritt 1 nach dem Ablegen der Dateien: Shopware-Update (Migrationen usw.) ohne Theme-Kompilierung.
     * Läuft in einer eigenen Anfrage, damit der neue Code geladen ist.
     */
    #[Route(path: '/api/_action/dh-github-updater/lifecycle/update/{pluginName}', name: 'api.action.dh_github_updater.lifecycle_update', requirements: ['pluginName' => '[A-Za-z][A-Za-z0-9_]{0,99}'], defaults: ['_acl' => ['system.plugin_maintain', 'system.plugin_upload']], methods: ['POST'])]
    public function lifecycleUpdate(string $pluginName, Context $context): JsonResponse
    {
        return $this->handle($context, true, fn (): array => $this->manager->runLifecycleUpdate(
            $pluginName,
            fn (): array => $this->stepwise->update($pluginName, $context),
            $context,
        ));
    }

    #[Route(path: '/api/_action/dh-github-updater/lifecycle/activate/{pluginName}', name: 'api.action.dh_github_updater.lifecycle_activate', requirements: ['pluginName' => '[A-Za-z][A-Za-z0-9_]{0,99}'], defaults: ['_acl' => ['system.plugin_maintain', 'system.plugin_upload']], methods: ['POST'])]
    public function lifecycleActivate(string $pluginName, Context $context): JsonResponse
    {
        return $this->handle($context, true, fn (): array => $this->manager->withDeployLock(
            fn (): array => $this->stepwise->installAndActivate($pluginName, $context),
        ));
    }

    /**
     * Schritt 2: Theme genau eines Verkaufskanals kompilieren.
     */
    #[Route(path: '/api/_action/dh-github-updater/compile-theme', name: 'api.action.dh_github_updater.compile_theme', defaults: ['_acl' => ['system.plugin_maintain', 'system.plugin_upload']], methods: ['POST'])]
    public function compileTheme(Request $request, Context $context): JsonResponse
    {
        return $this->handle($context, true, function () use ($request, $context): array {
            $salesChannelId = strtolower($this->getString($request, 'salesChannelId'));
            $themeId = strtolower($this->getString($request, 'themeId'));
            if (preg_match('/^[0-9a-f]{32}$/', $salesChannelId) !== 1 || preg_match('/^[0-9a-f]{32}$/', $themeId) !== 1) {
                throw new GithubUpdaterException('Ungültiger Verkaufskanal oder ungültiges Theme.', 400);
            }
            $this->stepwise->compileTheme($salesChannelId, $themeId, $context);

            return [];
        });
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function handle(Context $context, bool $modifies, callable $action): JsonResponse
    {
        try {
            $this->assertAccess($context, $modifies);

            return new JsonResponse(['success' => true] + $action());
        } catch (GithubUpdaterException $e) {
            $body = ['success' => false, 'message' => $e->getMessage()];
            if ($e instanceof UpdateRolledBackException) {
                $body['restored'] = true;
            }

            return new JsonResponse($body, $e->getStatusCode());
        } catch (\Throwable $e) {
            $this->logger->error('DhGithubUpdater: Unerwarteter Fehler.', ['exception' => $e]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Interner Fehler. Details stehen im Shopware-Log (var/log).',
            ], 500);
        }
    }

    private function assertAccess(Context $context, bool $modifies): void
    {
        $source = $context->getSource();

        if (!$source instanceof AdminApiSource || $source->getUserId() === null) {
            throw new GithubUpdaterException('Diese Funktion steht nur angemeldeten Administrations-Benutzern zur Verfügung.', 403);
        }

        if ($this->config->isAdminOnly() && !$source->isAdmin()) {
            throw new GithubUpdaterException('Diese Funktion ist laut Plugin-Konfiguration nur für Administratoren freigegeben.', 403);
        }

        if ($modifies && !$this->runtimeExtensionManagement) {
            throw new GithubUpdaterException('Die Erweiterungsverwaltung ist in dieser Umgebung deaktiviert (shopware.deployment.runtime_extension_management).', 403);
        }
    }

    private function getString(Request $request, string $key): string
    {
        $value = $request->request->all()[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}

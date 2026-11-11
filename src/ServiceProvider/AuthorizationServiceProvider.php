<?php

declare(strict_types=1);

namespace Guild\Framework\ServiceProvider;

use Guild\Framework\Admin\Actor;
use Guild\Framework\Admin\AdminPage;
use Guild\Framework\Admin\Csrf;
use Guild\Framework\Admin\Flash;
use Guild\Framework\Admin\PermissionCatalog;
use Guild\Framework\Application;
use Guild\Framework\Authentication\SignInMethod;
use Guild\Framework\Authorization\AdminMenu;
use Guild\Framework\Authorization\AdminNavigation;
use Guild\Framework\Authorization\AuthorizationConfiguration;
use Guild\Framework\Authorization\Gate;
use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Identity\IdentityReader;
use Guild\Framework\Authorization\Identity\RemoteUserIdentityReader;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\Authorization\Membership\GroupDirectory;
use Guild\Framework\Authorization\Membership\GrouperDirectoryConfiguration;
use Guild\Framework\Authorization\Membership\GrouperGroupDirectory;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Permission;
use Guild\Framework\Authorization\Policy\PolicyRegistry;
use Guild\Framework\Authorization\StatusPage;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Controller\Authorization\GroupController;
use Guild\Framework\Controller\Authorization\OverviewController;
use Guild\Framework\Controller\Authorization\RoleController;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Middleware\AuthorizationFailureResponder;
use Guild\Framework\Middleware\RequireSystemAdmin;
use Guild\Framework\Middleware\VerifyCsrfToken;
use Guild\Framework\Rivet\ContainerNavigation;
use Guild\Framework\Session\SessionStore;
use Guild\Grouper\GrouperClient;
use Guild\Rivet\Page\NavigationProvider;
use Guild\Rivet\Page\PageDefaults;
use Guild\Rivet\Render\Renderer;
use Guild\Rivet\Rivet;
use GuzzleHttp\Client;
use Illuminate\Database\Capsule\Manager as Capsule;
use League\Container\DefinitionContainerInterface;
use League\Container\ServiceProvider\AbstractServiceProvider;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class AuthorizationServiceProvider extends AbstractServiceProvider
{
    /**
     * @param  class-string<Permission>  $permissionEnum
     * @param  list<class-string>  $policies
     */
    public function __construct(
        private readonly IdentitySource $identitySource,
        private readonly string $permissionEnum,
        private readonly AuthorizationConfiguration $config,
        private readonly GrouperDirectoryConfiguration $directory,
        private readonly array $policies = [],
        private readonly ?AdminMenu $adminMenu = new AdminMenu(),
    ) {
    }

    public function provides(string $id): bool
    {
        $services = [
            AdminPage::class,
            StatusPage::class,
            AuthorizationFailureResponder::class,
            Csrf::class,
            Flash::class,
            PermissionCatalog::class,
            RequireSystemAdmin::class,
            VerifyCsrfToken::class,
            OverviewController::class,
            GroupController::class,
            RoleController::class,
            Gate::class,
            PolicyRegistry::class,
            UserResolver::class,
            IdentityReader::class,
            MembershipProvider::class,
            MembershipCache::class,
            GrantRepository::class,
            GrouperClient::class,
            GroupDirectory::class,
            AuthorizationConfiguration::class,
        ];

        if ($this->adminMenu !== null) {
            $services[] = NavigationProvider::class;
        }

        return in_array($id, $services, true);
    }

    public function register(): void
    {
        $container = $this->getContainer();
        $config = $this->config;

        $container->addShared(AuthorizationConfiguration::class, $config);

        $directory = $this->directory;

        $container->addShared(GrouperClient::class, static fn (): GrouperClient => new GrouperClient(
            $directory->grouper,
            new Client([
                'timeout' => $directory->timeout,
                'connect_timeout' => $directory->connectTimeout,
            ]),
        ));

        $session = static function () use ($container): SessionStore {
            /** @var SessionStore $session */
            $session = $container->get(SessionStore::class);

            return $session;
        };

        $container->addShared(MembershipCache::class, static fn (): MembershipCache => new MembershipCache($session()));

        $container->addShared(GroupDirectory::class, static function () use ($container): GroupDirectory {
            /** @var GrouperClient $grouper */
            $grouper = $container->get(GrouperClient::class);

            return new GrouperGroupDirectory($grouper);
        });

        $container->addShared(MembershipProvider::class, static function () use ($container, $config): MembershipProvider {
            /** @var GroupDirectory $directory */
            $directory = $container->get(GroupDirectory::class);
            /** @var MembershipCache $cache */
            $cache = $container->get(MembershipCache::class);
            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);

            return new MembershipProvider(
                $directory,
                $cache,
                $logger,
                $config->membershipTtl,
                $config->staleCap,
                time(...),
            );
        });

        $container->addShared(GrantRepository::class, static function () use ($container): GrantRepository {
            /** @var Capsule $capsule */
            $capsule = $container->get(Capsule::class);

            return new GrantRepository($capsule->getConnection());
        });

        $identitySource = $this->identitySource;

        $container->addShared(IdentityReader::class, static function () use ($container, $identitySource): IdentityReader {
            if ($identitySource === IdentitySource::Oidc) {
                /** @var SignInMethod $signIn */
                $signIn = $container->get(SignInMethod::class);

                return $signIn;
            }

            /** @var ServerRequestInterface $request */
            $request = $container->get(ServerRequestInterface::class);

            return new RemoteUserIdentityReader($request);
        });

        $permissionEnum = $this->permissionEnum;

        $container->addShared(UserResolver::class, static function () use ($container, $config, $permissionEnum): UserResolver {
            /** @var IdentityReader $identities */
            $identities = $container->get(IdentityReader::class);
            /** @var MembershipProvider $memberships */
            $memberships = $container->get(MembershipProvider::class);
            /** @var GrantRepository $grants */
            $grants = $container->get(GrantRepository::class);

            return new UserResolver($identities, $memberships, $grants, $permissionEnum, $config->systemAdminGroup);
        });

        $policies = $this->policies;

        $container->addShared(PolicyRegistry::class, static fn (): PolicyRegistry => new PolicyRegistry(
            $container,
            $policies,
            $permissionEnum,
        ));

        $container->addShared(Gate::class, static function () use ($container, $permissionEnum): Gate {
            /** @var UserResolver $users */
            $users = $container->get(UserResolver::class);
            /** @var PolicyRegistry $policies */
            $policies = $container->get(PolicyRegistry::class);

            return new Gate($users, $policies, $permissionEnum);
        });

        $this->registerFailureResponses($container);
        $this->registerAdministration($container, $permissionEnum);

        $adminMenu = $this->adminMenu;

        if ($adminMenu !== null) {
            $container->addShared(NavigationProvider::class, static function () use ($container, $adminMenu): NavigationProvider {
                /** @var UserResolver $users */
                $users = $container->get(UserResolver::class);
                /** @var ServerRequestInterface $request */
                $request = $container->get(ServerRequestInterface::class);

                return new AdminNavigation($users, $adminMenu, $request);
            });
        }
    }

    /**
     * The responses to authorization failures: the status page, in the
     * application's layout when Rivet was added, and the middleware that turns
     * a route's authorization exceptions into them. A sign-in method exists
     * only under OIDC; under CAS a guest has nowhere to be sent.
     */
    private function registerFailureResponses(DefinitionContainerInterface $container): void
    {
        $identitySource = $this->identitySource;

        $container->addShared(StatusPage::class, static fn (): StatusPage => new StatusPage(
            static function () use ($container): ?Renderer {
                if (! $container instanceof Application || ! $container->isRivetAdded()) {
                    return null;
                }

                $renderer = $container->get(Renderer::class);

                if (! $renderer instanceof Renderer) {
                    throw new ConfigurationException('The container did not return a Rivet Renderer.');
                }

                return $renderer;
            },
        ));

        $container->addShared(AuthorizationFailureResponder::class, static function () use ($container, $identitySource): AuthorizationFailureResponder {
            /** @var StatusPage $page */
            $page = $container->get(StatusPage::class);

            if ($identitySource !== IdentitySource::Oidc) {
                return new AuthorizationFailureResponder($page, null);
            }

            /** @var SignInMethod $signIn */
            $signIn = $container->get(SignInMethod::class);

            return new AuthorizationFailureResponder($page, $signIn);
        });
    }

    /**
     * The /framework/authorization pages: their layout, form protection, gate
     * and controllers. Bound explicitly, so the pages work without autowiring.
     *
     * @param  class-string<Permission>  $permissionEnum
     */
    private function registerAdministration(DefinitionContainerInterface $container, string $permissionEnum): void
    {
        $identitySource = $this->identitySource;

        $container->addShared(AdminPage::class, static fn (): AdminPage => new AdminPage(
            static function () use ($container): Renderer {
                if ($container instanceof Application && $container->isRivetAdded()) {
                    $renderer = $container->get(Renderer::class);

                    if (! $renderer instanceof Renderer) {
                        throw new ConfigurationException('The container did not return a Rivet Renderer.');
                    }

                    return $renderer;
                }

                // No application navigation to add to (Q28): the header holds the
                // administration menu alone.
                return new Renderer(
                    Rivet::registry(),
                    pageDefaults: new PageDefaults(appTitle: 'Administration', homeHref: '/framework/authorization'),
                    navigation: new ContainerNavigation($container),
                );
            },
        ));
        $session = static function () use ($container): SessionStore {
            /** @var SessionStore $session */
            $session = $container->get(SessionStore::class);

            return $session;
        };

        $container->addShared(Csrf::class, static fn (): Csrf => new Csrf($session()));
        $container->addShared(Flash::class, static fn (): Flash => new Flash($session()));
        $container->addShared(PermissionCatalog::class, static fn (): PermissionCatalog => new PermissionCatalog($permissionEnum));

        $page = static function () use ($container): AdminPage {
            /** @var AdminPage $page */
            $page = $container->get(AdminPage::class);

            return $page;
        };
        $flash = static function () use ($container): Flash {
            /** @var Flash $flash */
            $flash = $container->get(Flash::class);

            return $flash;
        };
        $catalog = static function () use ($container): PermissionCatalog {
            /** @var PermissionCatalog $catalog */
            $catalog = $container->get(PermissionCatalog::class);

            return $catalog;
        };

        $container->addShared(RequireSystemAdmin::class, static function () use ($container, $page, $identitySource): RequireSystemAdmin {
            /** @var UserResolver $users */
            $users = $container->get(UserResolver::class);

            return new RequireSystemAdmin($users, $page(), $identitySource);
        });
        $container->addShared(VerifyCsrfToken::class, static function () use ($container, $page): VerifyCsrfToken {
            /** @var Csrf $csrf */
            $csrf = $container->get(Csrf::class);

            return new VerifyCsrfToken($csrf, $page());
        });
        $container->addShared(OverviewController::class, static fn (): OverviewController => new OverviewController($page(), $flash(), $catalog()));
        $csrf = static function () use ($container): Csrf {
            /** @var Csrf $csrf */
            $csrf = $container->get(Csrf::class);

            return $csrf;
        };
        $actor = static function () use ($container): Actor {
            /** @var UserResolver $users */
            $users = $container->get(UserResolver::class);

            return new Actor($users);
        };

        $container->addShared(GroupController::class, static function () use ($container, $page, $flash, $csrf, $actor): GroupController {
            /** @var GrouperClient $grouper */
            $grouper = $container->get(GrouperClient::class);

            return new GroupController($page(), $flash(), $csrf(), $actor(), $grouper);
        });
        $container->addShared(RoleController::class, static fn (): RoleController => new RoleController($page(), $flash(), $csrf(), $actor(), $catalog()));

    }
}

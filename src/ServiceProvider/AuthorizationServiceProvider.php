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
use Guild\Framework\Extension\SlotClaim;
use Guild\Framework\Middleware\AuthorizationFailureResponder;
use Guild\Framework\Middleware\RequireSystemAdmin;
use Guild\Framework\Session\SessionStore;
use Guild\Grouper\GrouperClient;
use Guild\Rivet\Page\NavigationProvider;
use Guild\Rivet\Render\Renderer;
use GuzzleHttp\Client;
use Illuminate\Database\Capsule\Manager as Capsule;
use League\Container\DefinitionContainerInterface;
use League\Container\ServiceProvider\AbstractServiceProvider;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Binds the authorization services. Only ApplicationBuilder constructs it.
 *
 * @internal
 */
class AuthorizationServiceProvider extends AbstractServiceProvider
{
    /**
     * @param  string  $systemAdminGroup  The resolved ACM label of the System Admin group: an extension's
     *                                    choice, else the one in $config.
     * @param  GrouperDirectoryConfiguration|SlotClaim  $directory  Where groups come from: live Grouper, or
     *                                                              the class an extension chose. Only live
     *                                                              Grouper binds a GrouperClient.
     * @param  class-string<Permission>  $permissionEnum
     * @param  list<class-string>  $policies
     */
    public function __construct(
        private readonly IdentitySource $identitySource,
        private readonly string $permissionEnum,
        private readonly AuthorizationConfiguration $config,
        private readonly string $systemAdminGroup,
        private readonly GrouperDirectoryConfiguration|SlotClaim $directory,
        private readonly array $policies = [],
        private readonly ?AdminMenu $adminMenu = new AdminMenu(),
    ) {
    }

    public function provides(string $id): bool
    {
        $services = [
            StatusPage::class,
            AuthorizationFailureResponder::class,
            Flash::class,
            PermissionCatalog::class,
            RequireSystemAdmin::class,
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
            GroupDirectory::class,
            AuthorizationConfiguration::class,
        ];

        if ($this->directory instanceof GrouperDirectoryConfiguration) {
            $services[] = GrouperClient::class;
        }

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

        $this->registerGroupDirectory($container);

        $session = static function () use ($container): SessionStore {
            /** @var SessionStore $session */
            $session = $container->get(SessionStore::class);

            return $session;
        };

        $container->addShared(MembershipCache::class, static fn (): MembershipCache => new MembershipCache($session()));

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

        $systemAdminGroup = $this->systemAdminGroup;

        $container->addShared(UserResolver::class, static function () use ($container, $systemAdminGroup, $permissionEnum): UserResolver {
            /** @var IdentityReader $identities */
            $identities = $container->get(IdentityReader::class);
            /** @var MembershipProvider $memberships */
            $memberships = $container->get(MembershipProvider::class);
            /** @var GrantRepository $grants */
            $grants = $container->get(GrantRepository::class);

            return new UserResolver($identities, $memberships, $grants, $permissionEnum, $systemAdminGroup);
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
     * Where users' groups come from. Given the Grouper configuration, that is
     * a GrouperClient and the directory over it; given a claim, it is whichever
     * class an extension chose, and no GrouperClient is bound.
     */
    private function registerGroupDirectory(DefinitionContainerInterface $container): void
    {
        $directory = $this->directory;

        if ($directory instanceof SlotClaim) {
            $container->addShared(GroupDirectory::class, static fn (): mixed => $container->get($directory->value));

            return;
        }

        $container->addShared(GrouperClient::class, static fn (): GrouperClient => new GrouperClient(
            $directory->grouper,
            new Client([
                'timeout' => $directory->timeout,
                'connect_timeout' => $directory->connectTimeout,
            ]),
        ));

        $container->addShared(GroupDirectory::class, static function () use ($container): GroupDirectory {
            /** @var GrouperClient $grouper */
            $grouper = $container->get(GrouperClient::class);

            return new GrouperGroupDirectory($grouper);
        });
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
     * The /framework/authorization pages: their gate and controllers, on the
     * layout and form protection FrameworkPageServiceProvider binds. Bound
     * explicitly, so the pages work without autowiring.
     *
     * @param  class-string<Permission>  $permissionEnum
     */
    private function registerAdministration(DefinitionContainerInterface $container, string $permissionEnum): void
    {
        $identitySource = $this->identitySource;

        $session = static function () use ($container): SessionStore {
            /** @var SessionStore $session */
            $session = $container->get(SessionStore::class);

            return $session;
        };

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

        $hasGrouper = $this->directory instanceof GrouperDirectoryConfiguration;

        $container->addShared(GroupController::class, static function () use ($container, $page, $flash, $csrf, $actor, $hasGrouper): GroupController {
            /** @var GrouperClient|null $grouper */
            $grouper = $hasGrouper ? $container->get(GrouperClient::class) : null;

            return new GroupController($page(), $flash(), $csrf(), $actor(), $grouper);
        });
        $container->addShared(RoleController::class, static fn (): RoleController => new RoleController($page(), $flash(), $csrf(), $actor(), $catalog()));

    }
}

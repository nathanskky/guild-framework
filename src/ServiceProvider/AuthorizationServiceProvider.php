<?php

declare(strict_types=1);

namespace Guild\Framework\ServiceProvider;

use Guild\Access\Authentication\OIDC\OidcAuthenticationService;
use Guild\Framework\Authorization\AuthorizationConfiguration;
use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Identity\IdentityReader;
use Guild\Framework\Authorization\Identity\OidcIdentityReader;
use Guild\Framework\Authorization\Identity\RemoteUserIdentityReader;
use Guild\Framework\Authorization\IdentitySource;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Membership\Session;
use Guild\Framework\Authorization\Permission;
use Guild\Framework\Authorization\UserResolver;
use Guild\Grouper\GrouperClient;
use GuzzleHttp\Client;
use Illuminate\Database\Capsule\Manager as Capsule;
use League\Container\ServiceProvider\AbstractServiceProvider;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class AuthorizationServiceProvider extends AbstractServiceProvider
{
    /**
     * @param  class-string<Permission>  $permissionEnum
     */
    public function __construct(
        private readonly IdentitySource $identitySource,
        private readonly string $permissionEnum,
        private readonly AuthorizationConfiguration $config,
    ) {
    }

    public function provides(string $id): bool
    {
        $services = [
            UserResolver::class,
            IdentityReader::class,
            MembershipProvider::class,
            MembershipCache::class,
            GrantRepository::class,
            GrouperClient::class,
            AuthorizationConfiguration::class,
        ];

        return in_array($id, $services, true);
    }

    public function register(): void
    {
        $container = $this->getContainer();
        $config = $this->config;

        $container->addShared(AuthorizationConfiguration::class, $config);

        $container->addShared(GrouperClient::class, static fn (): GrouperClient => new GrouperClient(
            $config->grouper,
            new Client([
                'timeout' => $config->grouperTimeout,
                'connect_timeout' => $config->grouperConnectTimeout,
            ]),
        ));

        $container->addShared(MembershipCache::class, static fn (): MembershipCache => new MembershipCache(
            new Session(),
        ));

        $container->addShared(MembershipProvider::class, static function () use ($container, $config): MembershipProvider {
            /** @var GrouperClient $grouper */
            $grouper = $container->get(GrouperClient::class);
            /** @var MembershipCache $cache */
            $cache = $container->get(MembershipCache::class);
            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);

            return new MembershipProvider(
                $grouper,
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
                /** @var OidcAuthenticationService $authentication */
                $authentication = $container->get(OidcAuthenticationService::class);

                return new OidcIdentityReader($authentication);
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
    }
}

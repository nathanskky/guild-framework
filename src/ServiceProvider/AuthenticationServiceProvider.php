<?php

declare(strict_types=1);

namespace Guild\Framework\ServiceProvider;

use Guild\Access\Authentication\OIDC\OidcAuthenticationMiddleware;
use Guild\Access\Authentication\OIDC\OidcAuthenticationService;
use Guild\Access\Authentication\OIDC\OidcConfiguration;
use Guild\Framework\Authentication\IuLoginSignIn;
use Guild\Framework\Authentication\SignInMethod;
use Guild\Framework\Controller\Authentication\SignOutController;
use Guild\Framework\Extension\SlotClaim;
use Guild\Framework\Middleware\RequireSignIn;
use Guild\Framework\Session\SessionStore;
use League\Container\ServiceProvider\AbstractServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Binds the sign-in method and what depends on it. Given the IU Login
 * configuration, that is IuLoginSignIn and the guild/access services behind
 * it; given a claim, it is whichever class an extension chose, and nothing
 * from guild/access.
 *
 * Only ApplicationBuilder constructs it.
 *
 * @internal
 */
class AuthenticationServiceProvider extends AbstractServiceProvider
{
    public function __construct(private readonly OidcConfiguration|SlotClaim $signIn)
    {
    }

    public function provides(string $id): bool
    {
        $services = [
            RequireSignIn::class,
            SignInMethod::class,
            SignOutController::class,
        ];

        if ($this->signIn instanceof OidcConfiguration) {
            $services[] = OidcAuthenticationMiddleware::class;
            $services[] = OidcAuthenticationService::class;
            $services[] = OidcConfiguration::class;
        }

        return in_array($id, $services);
    }

    public function register(): void
    {
        $container = $this->getContainer();

        if ($this->signIn instanceof SlotClaim) {
            $claim = $this->signIn;
            $container->addShared(SignInMethod::class, static fn (): mixed => $container->get($claim->value));
        } else {
            $this->registerIuLogin($this->signIn);
        }

        $container->addShared(RequireSignIn::class)->addArgument(SignInMethod::class);
        $container->addShared(SignOutController::class)->addArgument(SignInMethod::class);
    }

    private function registerIuLogin(OidcConfiguration $config): void
    {
        $container = $this->getContainer();
        $container->addShared(OidcAuthenticationMiddleware::class)->addArguments([
            OidcConfiguration::class,
            LoggerInterface::class
        ]);
        $container->addShared(OidcAuthenticationService::class)->addArguments([
            OidcConfiguration::class,
            LoggerInterface::class
        ]);
        $container->addShared(SignInMethod::class, IuLoginSignIn::class)->addArguments([
            OidcAuthenticationService::class,
            SessionStore::class,
            LoggerInterface::class,
        ]);
        $container->addShared(OidcConfiguration::class, $config);
    }
}

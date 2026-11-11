<?php

declare(strict_types=1);

namespace Guild\Framework\ServiceProvider;

use Guild\Access\Authentication\OIDC\OidcAuthenticationMiddleware;
use Guild\Access\Authentication\OIDC\OidcAuthenticationService;
use Guild\Access\Authentication\OIDC\OidcConfiguration;
use Guild\Framework\Authentication\IuLoginSignIn;
use Guild\Framework\Authentication\SignInMethod;
use Guild\Framework\Session\SessionStore;
use League\Container\ServiceProvider\AbstractServiceProvider;
use Psr\Log\LoggerInterface;

class AuthenticationServiceProvider extends AbstractServiceProvider
{
    public function __construct(private readonly OidcConfiguration $config)
    {
    }

    public function provides(string $id): bool
    {
        $services = [
            OidcAuthenticationMiddleware::class,
            OidcAuthenticationService::class,
            OidcConfiguration::class,
            SignInMethod::class,
        ];
        return in_array($id, $services);
    }

    public function register(): void
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
        $container->addShared(OidcConfiguration::class, $this->config);
    }
}

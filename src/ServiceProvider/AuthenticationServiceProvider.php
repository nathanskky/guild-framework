<?php declare(strict_types=1);

namespace Shadow\Framework\ServiceProvider;

use League\Container\ServiceProvider\AbstractServiceProvider;
use League\Container\ServiceProvider\BootableServiceProviderInterface;
use Shadow\Access\Authentication\AuthenticationConfigurationInterface;
use Shadow\Access\Authentication\AuthenticationMiddleware;

class AuthenticationServiceProvider extends AbstractServiceProvider implements BootableServiceProviderInterface
{
    /**
     * The provides method is a way to let the container
     * know that a service is provided by this service
     * provider. Every service that is registered via
     * this service provider must have an alias added
     * to this array or it will be ignored.
     */
    public function provides(string $id): bool
    {
        $services = [
            AuthenticationConfigurationInterface::class,
            AuthenticationMiddleware::class,
        ];

        return in_array($id, $services);
    }

    /**
     * The register method is where you define services
     * in the same way you would directly with the container.
     * A convenience getter for the container is provided, you
     * can invoke any of the methods you would when defining
     * services directly, but remember, any alias added to the
     * container here, when passed to the `provides` method
     * must return true, or it will be ignored by the container.
     */
    public function register(): void
    {
        $authenticationConfig = $this->getContainer()->get(AuthenticationConfigurationInterface::class);
        $this->getContainer()->addShared(AuthenticationMiddleware::class)->addArgument($authenticationConfig);
    }

    public function boot(): void
    {
        $authenticationConfigFilePath = $this->getContainer()->get('path.base') . '/config/authentication.php';
        $authenticationConfig = require $authenticationConfigFilePath;

        if (! $authenticationConfig instanceof AuthenticationConfigurationInterface) {
            throw new \Exception(); // TODO
        }

        $this->getContainer()->addShared(AuthenticationConfigurationInterface::class, $authenticationConfig);
    }
}
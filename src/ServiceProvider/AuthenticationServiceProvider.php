<?php declare(strict_types=1);

namespace Shadow\Framework\ServiceProvider;

use Error;
use League\Container\ServiceProvider\AbstractServiceProvider;
use League\Container\ServiceProvider\BootableServiceProviderInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Shadow\Access\Authentication\AuthenticationConfigurationInterface;
use Shadow\Access\Authentication\AuthenticationMiddleware;
use Shadow\Framework\Exception\ConfigurationException;

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

    /**
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function boot(): void
    {
        // TODO: move authentication file name to Application class, for centralization
        $authenticationConfigFilePath = $this->getContainer()->get('path.config') . '/authentication.php';

        try {
            $authenticationConfig = require $authenticationConfigFilePath;
        } catch (Error $error) {
            throw new ConfigurationException(
                'Problem encountered while attempting to read authentication config file: '
                . $error->getMessage()
            );
        }

        if (!($authenticationConfig instanceof AuthenticationConfigurationInterface)) {
            throw new ConfigurationException(
                'Expected the authentication config file to return an object implementing '
                . AuthenticationConfigurationInterface::class
                . ', but '
                . is_object($authenticationConfig) ? $authenticationConfig::class : gettype($authenticationConfig)
                . ' returned instead.'
            );
        }

        $this->getContainer()->addShared(AuthenticationConfigurationInterface::class, $authenticationConfig);
    }
}
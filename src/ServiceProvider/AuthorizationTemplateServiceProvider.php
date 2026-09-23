<?php

declare(strict_types=1);

namespace Guild\Framework\ServiceProvider;

use Guild\Framework\Authorization\Gate;
use Guild\Framework\Authorization\Permission;
use Guild\Framework\Authorization\TemplateAuthorization;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Latte\AuthorizationExtension as LatteAuthorizationExtension;
use Guild\Framework\TemplateEngine;
use Guild\Framework\Twig\AuthorizationExtension as TwigAuthorizationExtension;
use Latte\Engine;
use League\Container\ServiceProvider\AbstractServiceProvider;
use League\Container\ServiceProvider\BootableServiceProviderInterface;
use Twig\Environment;

/**
 * Adds can() and cannot() to the configured template engine.
 *
 * Eager for the same reason as RivetServiceProvider: nothing asks the
 * container for these, templates reach them through the engine. Registered by
 * whichever of addAuthorization() and addTemplateEngine() is called second,
 * so the two can be called in either order.
 */
class AuthorizationTemplateServiceProvider extends AbstractServiceProvider implements BootableServiceProviderInterface
{
    /**
     * @param  class-string<Permission>  $permissionEnum
     */
    public function __construct(
        private readonly TemplateEngine $templateEngine,
        private readonly string $permissionEnum,
    ) {
    }

    public function boot(): void
    {
        $container = $this->getContainer();

        $authorization = new TemplateAuthorization(
            static function () use ($container): Gate {
                $gate = $container->get(Gate::class);

                if (! $gate instanceof Gate) {
                    throw new ConfigurationException('The container did not return a Gate.');
                }

                return $gate;
            },
            $this->permissionEnum,
        );

        if ($this->templateEngine === TemplateEngine::Twig) {
            $container->extend(Environment::class)
                ->addMethodCall('addExtension', [new TwigAuthorizationExtension($authorization)]);
        }

        if ($this->templateEngine === TemplateEngine::Latte) {
            $container->extend(Engine::class)
                ->addMethodCall('addExtension', [new LatteAuthorizationExtension($authorization)]);
        }
    }

    public function provides(string $id): bool
    {
        return false;
    }

    /**
     * Deliberately empty: boot() does all the work, and this provider binds no
     * services of its own.
     */
    public function register(): void
    {
    }
}

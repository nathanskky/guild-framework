<?php

declare(strict_types=1);

namespace Guild\Framework\ServiceProvider;

use Guild\Framework\Admin\Csrf;
use Guild\Framework\Authentication\SignOutForm;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Latte\AuthenticationExtension as LatteAuthenticationExtension;
use Guild\Framework\TemplateEngine;
use Guild\Framework\Twig\AuthenticationExtension as TwigAuthenticationExtension;
use Latte\Engine;
use League\Container\ServiceProvider\AbstractServiceProvider;
use League\Container\ServiceProvider\BootableServiceProviderInterface;
use Twig\Environment;

/**
 * Adds sign_out_form() to the configured template engine.
 *
 * Eager for the same reason as AuthorizationTemplateServiceProvider: nothing
 * asks the container for it, templates reach it through the engine. Registered
 * by whichever of addAuthentication() and addTemplateEngine() is called second,
 * so the two can be called in either order.
 *
 * @internal
 */
class AuthenticationTemplateServiceProvider extends AbstractServiceProvider implements BootableServiceProviderInterface
{
    public function __construct(private readonly TemplateEngine $templateEngine)
    {
    }

    #[\Override]
    public function boot(): void
    {
        $container = $this->getContainer();

        $form = new SignOutForm(static function () use ($container): Csrf {
            $csrf = $container->get(Csrf::class);

            if (! $csrf instanceof Csrf) {
                throw new ConfigurationException('The container did not return a Csrf.');
            }

            return $csrf;
        });

        if ($this->templateEngine === TemplateEngine::Twig) {
            $container->extend(Environment::class)
                ->addMethodCall('addExtension', [new TwigAuthenticationExtension($form)]);
        }

        if ($this->templateEngine === TemplateEngine::Latte) {
            $container->extend(Engine::class)
                ->addMethodCall('addExtension', [new LatteAuthenticationExtension($form)]);
        }
    }

    #[\Override]
    public function provides(string $id): bool
    {
        return false;
    }

    /**
     * Deliberately empty: boot() does all the work, and this provider binds no
     * services of its own.
     */
    #[\Override]
    public function register(): void
    {
    }
}

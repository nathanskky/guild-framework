<?php

declare(strict_types=1);

namespace Guild\Framework\ServiceProvider;

use Guild\Framework\TemplateEngine;
use Guild\Rivet\Latte\RivetExtension as LatteRivetExtension;
use Guild\Rivet\Render\ComponentRegistry;
use Guild\Rivet\Render\Renderer;
use Guild\Rivet\Rivet;
use Guild\Rivet\Twig\RivetExtension as TwigRivetExtension;
use Guild\Rivet\Twig\RivetRuntime;
use Latte\Engine;
use League\Container\ServiceProvider\AbstractServiceProvider;
use League\Container\ServiceProvider\BootableServiceProviderInterface;
use Twig\Environment;
use Twig\RuntimeLoader\ContainerRuntimeLoader;

/**
 * Registers the Rivet components against the configured template engine.
 *
 * **This provider is eager, and has to be.** A lazy provider only registers when
 * something asks for one of the services it declares, and nothing ever asks for these:
 * templates reach the components through the engine, which ViewServiceProvider binds.
 * Left lazy, the extension would simply never be added and every `{% rvt_* %}` tag would
 * fail as an unknown tag. `boot()` runs the moment the provider is added, so everything
 * is wired before the first template is rendered.
 *
 * The engine binding is extended rather than replaced. `extend()` forces the owning
 * provider to register first, so this works even though ViewServiceProvider is lazy.
 *
 * There is no per-request reset call. `Renderer::reset()` matters only where a process
 * outlives a request; this framework builds its container per request, so each one
 * already starts with a fresh renderer and identifiers numbered from one.
 */
class RivetServiceProvider extends AbstractServiceProvider implements BootableServiceProviderInterface
{
    public function __construct(private readonly TemplateEngine $templateEngine)
    {
    }

    public function boot(): void
    {
        $container = $this->getContainer();

        // Built here rather than resolved back out of the container: the container
        // returns mixed, and feeding that to a typed constructor is exactly the pattern
        // that makes ViewServiceProvider the worst offender in this repo's static
        // analysis. These are cheap objects; constructing them directly keeps the types.
        $registry = Rivet::registry();
        $renderer = new Renderer($registry);

        $container->addShared(ComponentRegistry::class, $registry);
        $container->addShared(Renderer::class, $renderer);
        $container->addShared(RivetRuntime::class, new RivetRuntime($renderer));

        if ($this->templateEngine === TemplateEngine::Twig) {
            $container->extend(Environment::class)
                ->addMethodCall('addExtension', [new TwigRivetExtension($registry)])
                // Twig resolves the runtime through the container, so compiled templates
                // use the same renderer bound above.
                ->addMethodCall('addRuntimeLoader', [new ContainerRuntimeLoader($container)]);
        }

        if ($this->templateEngine === TemplateEngine::Latte) {
            $container->extend(Engine::class)
                ->addMethodCall('addExtension', [new LatteRivetExtension($registry, $renderer)]);
        }
    }

    /**
     * Nothing is provided lazily; boot() has already bound everything.
     */
    public function provides(string $id): bool
    {
        return false;
    }

    public function register(): void
    {
        // Intentionally empty. See boot().
    }
}

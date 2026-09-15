<?php

declare(strict_types=1);

namespace Guild\Framework\ServiceProvider;

use Guild\Framework\TemplateEngine;
use Guild\Rivet\Latte\RivetExtension as LatteRivetExtension;
use Guild\Rivet\Page\PageDefaults;
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
 * `provides()` still declares the three services this binds. They are bound in `boot()`
 * as container definitions, which the container resolves before it consults any
 * provider — so the declaration changes no behaviour, but a provider that binds a
 * service and denies providing it is lying about its own contract.
 *
 * There is no per-request reset call. `Renderer::reset()` matters only where a process
 * outlives a request; this framework builds its container per request, so each one
 * already starts with a fresh renderer and identifiers numbered from one.
 */
class RivetServiceProvider extends AbstractServiceProvider implements BootableServiceProviderInterface
{
    public function __construct(
        private readonly TemplateEngine $templateEngine,
        private readonly ?PageDefaults $pageDefaults = null,
    ) {
    }

    public function boot(): void
    {
        $container = $this->getContainer();

        // Built here rather than resolved back out of the container: the container
        // returns mixed, and feeding that to a typed constructor is exactly the pattern
        // that makes ViewServiceProvider the worst offender in this repo's static
        // analysis. These are cheap objects; constructing them directly keeps the types.
        $registry = Rivet::registry();
        $renderer = new Renderer($registry, pageDefaults: $this->pageDefaults);

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

    public function provides(string $id): bool
    {
        $services = [
            ComponentRegistry::class,
            Renderer::class,
            RivetRuntime::class,
        ];

        return in_array($id, $services, true);
    }

    /**
     * Deliberately empty, and unreachable: boot() has already bound everything this
     * provider declares, and the container resolves definitions before it consults
     * providers, so the lazy path never runs.
     *
     * Moving the bindings here would be worse, not tidier. boot() must construct the
     * renderer eagerly because the Latte extension captures it, so a later register()
     * that rebound a fresh one would leave the container and the Latte extension holding
     * different renderers with diverging identifier sequences.
     */
    public function register(): void
    {
    }
}

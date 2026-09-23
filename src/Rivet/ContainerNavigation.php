<?php

declare(strict_types=1);

namespace Guild\Framework\Rivet;

use Guild\Framework\Exception\ConfigurationException;
use Guild\Rivet\Page\NavigationProvider;
use Guild\Rivet\Page\PageDefaults;
use Psr\Container\ContainerInterface;

/**
 * The NavigationProvider every Rivet renderer in a Guild application is built
 * with. It delegates to whatever provider the container holds under the
 * NavigationProvider id at render time, and otherwise leaves the configured
 * items alone.
 *
 * The indirection is what lets addRivet() and addAuthorization() be called in
 * either order: the renderer is built when addRivet() runs, before the
 * authorization layer may have registered its provider.
 *
 * @internal
 */
final readonly class ContainerNavigation implements NavigationProvider
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function navItems(PageDefaults $defaults): array
    {
        // An interface is never autowired, so this is true only when a
        // provider has actually been bound.
        if (! $this->container->has(NavigationProvider::class)) {
            return $defaults->navItems;
        }

        $provider = $this->container->get(NavigationProvider::class);

        if (! $provider instanceof NavigationProvider) {
            throw new ConfigurationException('The container did not return a NavigationProvider.');
        }

        return $provider->navItems($defaults);
    }
}

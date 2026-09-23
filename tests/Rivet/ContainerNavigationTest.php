<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Rivet;

use Guild\Framework\Rivet\ContainerNavigation;
use Guild\Rivet\Page\NavigationProvider;
use Guild\Rivet\Page\PageDefaults;
use League\Container\Container;
use League\Container\ReflectionContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContainerNavigation::class)]
final class ContainerNavigationTest extends TestCase
{
    public function testWithNothingBoundTheConfiguredItemsAreUsed(): void
    {
        $container = new Container();
        $container->delegate(new ReflectionContainer());
        $defaults = $this->defaults();

        self::assertSame($defaults->navItems, new ContainerNavigation($container)->navItems($defaults), 'autowiring never supplies an interface');
    }

    public function testABoundProviderIsAskedAtRenderTime(): void
    {
        $container = new Container();
        $navigation = new ContainerNavigation($container);

        $container->addShared(NavigationProvider::class, new class () implements NavigationProvider {
            public function navItems(PageDefaults $defaults): array
            {
                return [['label' => 'Bound later']];
            }
        });

        self::assertSame([['label' => 'Bound later']], $navigation->navItems($this->defaults()), 'a provider bound after construction is still found');
    }

    private function defaults(): PageDefaults
    {
        return new PageDefaults(appTitle: 'Course Catalog', navItems: [['label' => 'Courses', 'href' => '/courses']]);
    }
}

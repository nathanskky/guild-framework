<?php

declare(strict_types=1);

namespace Guild\Framework\ServiceProvider;

use Guild\Framework\Admin\AdminPage;
use Guild\Framework\Admin\Csrf;
use Guild\Framework\Application;
use Guild\Framework\Authorization\StatusPage;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Middleware\VerifyCsrfToken;
use Guild\Framework\Rivet\ContainerNavigation;
use Guild\Framework\Session\SessionStore;
use Guild\Rivet\Page\PageDefaults;
use Guild\Rivet\Render\Renderer;
use Guild\Rivet\Rivet;
use League\Container\ServiceProvider\AbstractServiceProvider;

/**
 * What every page the framework serves shares: the page layout, the status
 * page (404, 405 and the authorization failures) and the form protection. Registered with the application's base bindings, so sign-out and
 * the administration pages use the same ones, whichever of them an application
 * enables.
 *
 * @internal
 */
class FrameworkPageServiceProvider extends AbstractServiceProvider
{
    #[\Override]
    public function provides(string $id): bool
    {
        return in_array($id, [AdminPage::class, Csrf::class, StatusPage::class, VerifyCsrfToken::class], true);
    }

    #[\Override]
    public function register(): void
    {
        $container = $this->getContainer();

        $container->addShared(AdminPage::class, static fn (): AdminPage => new AdminPage(
            static function () use ($container): Renderer {
                if ($container instanceof Application && $container->isRivetAdded()) {
                    $renderer = $container->get(Renderer::class);

                    if (! $renderer instanceof Renderer) {
                        throw new ConfigurationException('The container did not return a Rivet Renderer.');
                    }

                    return $renderer;
                }

                // No application navigation to add to (Q28): the header holds the
                // administration menu alone.
                return new Renderer(
                    Rivet::registry(),
                    pageDefaults: new PageDefaults(appTitle: 'Administration', homeHref: '/framework/authorization'),
                    navigation: new ContainerNavigation($container),
                );
            },
        ));

        $container->addShared(StatusPage::class, static fn (): StatusPage => new StatusPage(
            static function () use ($container): ?Renderer {
                if (! $container instanceof Application || ! $container->isRivetAdded()) {
                    return null;
                }

                $renderer = $container->get(Renderer::class);

                if (! $renderer instanceof Renderer) {
                    throw new ConfigurationException('The container did not return a Rivet Renderer.');
                }

                return $renderer;
            },
        ));

        $container->addShared(Csrf::class, static function () use ($container): Csrf {
            /** @var SessionStore $session */
            $session = $container->get(SessionStore::class);

            return new Csrf($session);
        });

        $container->addShared(VerifyCsrfToken::class, static function () use ($container): VerifyCsrfToken {
            /** @var Csrf $csrf */
            $csrf = $container->get(Csrf::class);
            /** @var StatusPage $page */
            $page = $container->get(StatusPage::class);

            return new VerifyCsrfToken($csrf, $page);
        });
    }
}

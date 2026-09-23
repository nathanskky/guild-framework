<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use Guild\Framework\Exception\AuthorizationUnavailableException;
use Guild\Rivet\Page\NavigationProvider;
use Guild\Rivet\Page\PageDefaults;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Adds the framework's administration menu to the header for members of the
 * System Admin group.
 *
 * Hiding the menu is cosmetic: the /framework routes check the group
 * themselves. So when the check cannot be answered — Grouper unavailable past
 * the stale cap — the menu is left out and the page still renders, rather than
 * a navigation bar taking every page down with it.
 *
 * @internal
 *
 * @phpstan-import-type NavItem from PageDefaults
 */
final readonly class AdminNavigation implements NavigationProvider
{
    private const string BASE_PATH = '/framework';

    public function __construct(
        private UserResolver $users,
        private AdminMenu $menu,
        private ServerRequestInterface $request,
    ) {
    }

    public function navItems(PageDefaults $defaults): array
    {
        $items = $defaults->navItems;

        if (! $this->isSystemAdmin()) {
            return $items;
        }

        array_splice($items, $this->menu->position ?? count($items), 0, [$this->item()]);

        return $items;
    }

    private function isSystemAdmin(): bool
    {
        try {
            return $this->users->current()?->isSystemAdmin() === true;
        } catch (AuthorizationUnavailableException) {
            return false;
        }
    }

    /**
     * @return NavItem
     */
    private function item(): array
    {
        $path = $this->request->getUri()->getPath();

        return [
            'label' => $this->menu->label,
            'current' => $path === self::BASE_PATH || str_starts_with($path, self::BASE_PATH . '/'),
            'children' => [
                ['label' => 'Authorization', 'href' => self::BASE_PATH . '/authorization'],
            ],
        ];
    }
}

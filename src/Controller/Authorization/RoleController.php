<?php

declare(strict_types=1);

namespace Guild\Framework\Controller\Authorization;

use Guild\Framework\Admin\AdminPage;
use Guild\Framework\Admin\Flash;
use Guild\Framework\Admin\Markup;
use Guild\Framework\Admin\PermissionCatalog;
use Guild\Framework\Model\Group;
use Guild\Framework\Model\Role;
use Guild\Framework\Model\RolePermission;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
final readonly class RoleController
{
    public function __construct(
        private AdminPage $page,
        private Flash $flash,
        private PermissionCatalog $permissions,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $rows = [];

        foreach (Role::query()->with(['groups', 'permissions'])->get()->sortBy('name') as $role) {
            $rows[] = [
                $role->name,
                $role->active ? 'Yes' : 'No',
                implode(', ', $role->groups->map(static fn (Group $group): string => $group->name)->all()),
                implode(', ', $role->permissions->map(fn (RolePermission $grant): string => $this->permissions->isKnown($grant->name)
                    ? $grant->name
                    : "{$grant->name} (not defined by the application)")->all()),
            ];
        }

        $body = Markup::flash($this->flash->take())
            . Markup::table('Roles', ['Name', 'Active', 'Groups', 'Permissions'], $rows, 'No roles exist yet.');

        return $this->page->response('Roles', $body, [
            ['label' => 'Authorization', 'href' => '/framework/authorization'],
            ['label' => 'Roles'],
        ]);
    }
}

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
use Guild\Rivet\Html\Html;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
final readonly class OverviewController
{
    public function __construct(
        private AdminPage $page,
        private Flash $flash,
        private PermissionCatalog $permissions,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $orphans = [];

        foreach (RolePermission::query()->with('role')->get()->sortBy('name') as $grant) {
            if (! $this->permissions->isKnown($grant->name)) {
                $orphans[] = "{$grant->role->name}: {$grant->name}";
            }
        }

        $body = Markup::flash($this->flash->take())
            . ($orphans === [] ? '' : Markup::warning(
                'Some grants name permissions the application no longer defines. They grant nothing.',
                $orphans,
            ))
            . Html::el('ul')->children(
                Html::el('li')->children(Markup::link('/framework/authorization/groups', sprintf('Groups (%d)', Group::query()->count()))),
                Html::el('li')->children(Markup::link('/framework/authorization/roles', sprintf('Roles (%d)', Role::query()->count()))),
            )->render();

        return $this->page->response('Authorization', $body);
    }
}

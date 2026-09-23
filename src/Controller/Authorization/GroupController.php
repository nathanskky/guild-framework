<?php

declare(strict_types=1);

namespace Guild\Framework\Controller\Authorization;

use Guild\Framework\Admin\AdminPage;
use Guild\Framework\Admin\Flash;
use Guild\Framework\Admin\Markup;
use Guild\Framework\Model\Group;
use Guild\Framework\Model\Role;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 */
final readonly class GroupController
{
    public function __construct(private AdminPage $page, private Flash $flash)
    {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $rows = [];

        foreach (Group::query()->with('roles')->get()->sortBy('name') as $group) {
            $rows[] = [
                $group->name,
                Markup::code($group->group_identifier),
                $group->active ? 'Yes' : 'No',
                implode(', ', $group->roles->map(static fn (Role $role): string => $role->name)->all()),
            ];
        }

        $body = Markup::flash($this->flash->take())
            . Markup::table('Registered groups', ['Name', 'Grouper identifier', 'Active', 'Roles'], $rows, 'No groups are registered yet.');

        return $this->page->response('Groups', $body, [
            ['label' => 'Authorization', 'href' => '/framework/authorization'],
            ['label' => 'Groups'],
        ]);
    }
}

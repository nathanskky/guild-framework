<?php

declare(strict_types=1);

namespace Guild\Framework\Controller\Authorization;

use Guild\Framework\Admin\Actor;
use Guild\Framework\Admin\AdminPage;
use Guild\Framework\Admin\Csrf;
use Guild\Framework\Admin\Flash;
use Guild\Framework\Admin\FormBuilder;
use Guild\Framework\Admin\FormInput;
use Guild\Framework\Admin\Markup;
use Guild\Framework\Admin\PermissionCatalog;
use Guild\Framework\Authorization\Permission;
use Guild\Framework\Model\Group;
use Guild\Framework\Model\Role;
use Guild\Framework\Model\RolePermission;
use Guild\Rivet\Enum\ButtonPurpose;
use Guild\Rivet\Html\Html;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Creates roles and grants them the application's permissions.
 *
 * The permissions offered are exactly the cases of the application's enum, so
 * an administrator cannot grant a name no code checks. A stored grant whose
 * name the enum no longer defines grants nothing; it is shown so it can be
 * removed. Which groups hold a role is edited on the group's page.
 *
 * @internal
 */
final readonly class RoleController
{
    private const string BASE = '/framework/authorization/roles';

    public function __construct(
        private AdminPage $page,
        private Flash $flash,
        private Csrf $csrf,
        private Actor $actor,
        private PermissionCatalog $permissions,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $rows = [];

        foreach (Role::query()->with(['groups', 'permissions'])->get()->sortBy('name') as $role) {
            $rows[] = [
                Markup::link(self::BASE . '/' . $role->id, $role->name),
                $role->active ? 'Yes' : 'No',
                implode(', ', $role->groups->map(static fn (Group $group): string => $group->name)->all()),
                implode(', ', $role->permissions->map(fn (RolePermission $grant): string => $this->permissions->isKnown($grant->name)
                    ? $grant->name
                    : "{$grant->name} (not defined by the application)")->all()),
            ];
        }

        $body = Markup::flash($this->flash->take())
            . Html::el('p')->children(Markup::link(self::BASE . '/new', 'Create a role'))->render()
            . Markup::table('Roles', ['Name', 'Active', 'Groups', 'Permissions'], $rows, 'No roles exist yet.');

        return $this->page->response('Roles', $body, $this->crumbs());
    }

    public function createForm(ServerRequestInterface $request): ResponseInterface
    {
        // Active is pre-checked: someone creating a role means it to work.
        return $this->form(null, '', true, []);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $input = FormInput::from($request);
        $name = $input->string('name');
        $active = $input->checked('active');
        $granted = $this->knownPermissions($input->strings('permissions'));
        $error = $this->nameError($name, null);

        if ($error !== null) {
            return $this->form(null, $name, $active, $granted, [$error], 422);
        }

        $username = $this->actor->username();
        $role = new Role();

        $role->getConnection()->transaction(function () use ($role, $name, $active, $granted, $username): void {
            $role->name = $name;
            $role->active = $active;
            $role->created_by = $username;
            $role->save();

            $this->syncGrants($role, $granted, $username);
        });

        $this->flash->set("Created {$name}.");

        return new RedirectResponse(self::BASE . '/' . $role->id, 303);
    }

    /**
     * @param  array<string, string>  $args
     */
    public function edit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $role = $this->find($args);

        if ($role === null) {
            return $this->notFound();
        }

        $granted = array_values(array_map(
            static fn (RolePermission $grant): string => $grant->name,
            array_filter($role->permissions()->get()->all(), static fn (RolePermission $grant): bool => $grant->active),
        ));

        return $this->form($role, $role->name, $role->active, $granted);
    }

    /**
     * @param  array<string, string>  $args
     */
    public function update(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $role = $this->find($args);

        if ($role === null) {
            return $this->notFound();
        }

        $input = FormInput::from($request);
        $name = $input->string('name');
        $active = $input->checked('active');
        $granted = [...$this->knownPermissions($input->strings('permissions')), ...$this->keptOrphans($role, $input->strings('orphans'))];
        $error = $this->nameError($name, $role->id);

        if ($error !== null) {
            return $this->form($role, $name, $active, $granted, [$error], 422);
        }

        $username = $this->actor->username();

        $role->getConnection()->transaction(function () use ($role, $name, $active, $granted, $username): void {
            $role->name = $name;
            $role->active = $active;
            $role->updated_by = $username;
            $role->save();

            $this->syncGrants($role, $granted, $username);
        });

        $this->flash->set("Saved {$name}.");

        return new RedirectResponse(self::BASE . '/' . $role->id, 303);
    }

    /**
     * @param  array<string, string>  $args
     */
    public function confirmDelete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $role = $this->find($args);

        if ($role === null) {
            return $this->notFound();
        }

        $body = Html::el('p')->text(
            "Delete {$role->name}? Every group holding it loses its permissions, and its grants are deleted with it."
        )->render()
            . new FormBuilder(self::BASE . '/' . $role->id . '/delete', $this->csrf)
                ->render('Delete role', ButtonPurpose::Danger, self::BASE . '/' . $role->id);

        return $this->page->response('Delete ' . $role->name, $body, $this->crumbs($role));
    }

    /**
     * @param  array<string, string>  $args
     */
    public function delete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $role = $this->find($args);

        if ($role === null) {
            return $this->notFound();
        }

        // Explicit rather than left to the foreign key cascades, so the
        // outcome does not depend on the database engine.
        $role->getConnection()->transaction(static function () use ($role): void {
            $role->groups()->detach();
            $role->permissions()->delete();
            $role->delete();
        });

        $this->flash->set("Deleted {$role->name}.");

        return new RedirectResponse(self::BASE, 303);
    }

    /**
     * Make the role's active grants exactly $names: create what is missing,
     * reactivate what was switched off outside this page, delete the rest.
     *
     * @param  list<string>  $names
     */
    private function syncGrants(Role $role, array $names, string $username): void
    {
        $existing = [];

        foreach ($role->permissions()->get() as $grant) {
            if (! in_array($grant->name, $names, true)) {
                $grant->delete();

                continue;
            }

            if (! $grant->active) {
                $grant->active = true;
                $grant->updated_by = $username;
                $grant->save();
            }

            $existing[] = $grant->name;
        }

        foreach (array_diff($names, $existing) as $name) {
            $grant = new RolePermission();
            $grant->role_id = $role->id;
            $grant->name = $name;
            $grant->active = true;
            $grant->created_by = $username;
            $grant->save();
        }
    }

    /**
     * @param  list<string>  $granted
     * @param  list<string>  $errors
     */
    private function form(?Role $role, string $name, bool $active, array $granted, array $errors = [], int $status = 200): ResponseInterface
    {
        $options = array_map(static fn (Permission $permission): array => [
            'value' => (string) $permission->value,
            'label' => (string) $permission->value,
            'checked' => in_array((string) $permission->value, $granted, true),
            'description' => $permission->name,
        ], $this->permissions->cases());

        $action = $role === null ? self::BASE . '/new' : self::BASE . '/' . $role->id;

        $form = new FormBuilder($action, $this->csrf)
            ->text('name', 'Name', $name, $errors)
            ->checkbox('active', 'Active', $active, 'An inactive role grants nothing.');

        $form = $options === []
            ? $form->html(Html::el('p')->text('The application defines no permissions.')->render())
            : $form->checkboxes('permissions', 'Permissions', $options, 'Everyone holding this role is granted these.');

        $orphans = $role === null ? [] : $this->orphans($role);

        if ($orphans !== []) {
            $form = $form->checkboxes('orphans', 'No longer defined by the application', array_map(static fn (string $orphan): array => [
                'value' => $orphan,
                'label' => $orphan,
                'checked' => true,
            ], $orphans), 'These grant nothing. Clear one to delete it.');
        }

        $body = Markup::flash($this->flash->take()) . $form->render($role === null ? 'Create role' : 'Save', cancelHref: self::BASE);

        if ($role !== null) {
            $body .= $this->heldBy($role)
                . Html::el('p')->class('rvt-m-top-xl')->children(Markup::link(self::BASE . '/' . $role->id . '/delete', 'Delete this role'))->render();
        }

        return $this->page->response($role === null ? 'Create a role' : $role->name, $body, $this->crumbs($role), $status);
    }

    private function heldBy(Role $role): string
    {
        $groups = $role->groups()->get()->sortBy('name')->all();

        if ($groups === []) {
            return Html::el('p')->text('No group holds this role yet. Give it to a group from the group\'s page.')->render();
        }

        $list = Html::el('ul');

        foreach ($groups as $group) {
            $list->children(Html::el('li')->children(Markup::link('/framework/authorization/groups/' . $group->id, $group->name)));
        }

        return Html::el('h2')->class('rvt-ts-20', 'rvt-m-top-xl')->text('Held by')->render() . $list->render();
    }

    /**
     * @param  list<string>  $submitted
     * @return list<string>
     */
    private function knownPermissions(array $submitted): array
    {
        return array_values(array_filter($submitted, $this->permissions->isKnown(...)));
    }

    /**
     * Orphaned grants the administrator left checked. Only names the role
     * already holds count, so the orphan list cannot be used to grant anything.
     *
     * @param  list<string>  $submitted
     * @return list<string>
     */
    private function keptOrphans(Role $role, array $submitted): array
    {
        return array_values(array_intersect($submitted, $this->orphans($role)));
    }

    /**
     * @return list<string>
     */
    private function orphans(Role $role): array
    {
        $orphans = [];

        foreach ($role->permissions()->get() as $grant) {
            if (! $this->permissions->isKnown($grant->name)) {
                $orphans[] = $grant->name;
            }
        }

        return $orphans;
    }

    private function nameError(string $name, ?int $exceptId): ?string
    {
        if ($name === '') {
            return 'Enter a name.';
        }

        if (mb_strlen($name) > 255) {
            return 'Use at most 255 characters.';
        }

        $clash = Role::query()->where('name', $name)->first();

        return $clash instanceof Role && $clash->id !== $exceptId
            ? "Another role is already named \u{201C}{$name}\u{201D}."
            : null;
    }

    /**
     * @param  array<string, string>  $args
     */
    private function find(array $args): ?Role
    {
        $role = Role::query()->find((int) ($args['id'] ?? 0));

        return $role instanceof Role ? $role : null;
    }

    private function notFound(): ResponseInterface
    {
        return $this->page->response('Not found', Html::el('p')->text('There is no such role.')->render(), $this->crumbs(), 404);
    }

    /**
     * @return list<array{label: string, href?: string}>
     */
    private function crumbs(?Role $role = null): array
    {
        $crumbs = [['label' => 'Authorization', 'href' => '/framework/authorization'], ['label' => 'Roles', 'href' => self::BASE]];

        if ($role !== null) {
            $crumbs[] = ['label' => $role->name, 'href' => self::BASE . '/' . $role->id];
        }

        return $crumbs;
    }
}

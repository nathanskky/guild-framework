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
use Guild\Framework\Model\Group;
use Guild\Framework\Model\Role;
use Guild\Grouper\GrouperClient;
use Guild\Grouper\GrouperGroup;
use Guild\Grouper\GrouperUnavailable;
use Guild\Rivet\Enum\ButtonPurpose;
use Guild\Rivet\Html\Html;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Registers Grouper groups with the application and maps them to roles.
 *
 * Groups themselves are managed in ACM, never here. Registering one records
 * its identifier, resolved from the ACM label an administrator types, so the
 * thing verified and the thing matched are always the same.
 *
 * @internal
 */
final readonly class GroupController
{
    private const string BASE = '/framework/authorization/groups';

    public function __construct(
        private AdminPage $page,
        private Flash $flash,
        private Csrf $csrf,
        private Actor $actor,
        private GrouperClient $grouper,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $rows = [];

        foreach (Group::query()->with('roles')->get()->sortBy('name') as $group) {
            $rows[] = [
                Markup::link(self::BASE . '/' . $group->id, $group->name),
                Markup::code($group->group_identifier),
                $group->active ? 'Yes' : 'No',
                implode(', ', $group->roles->map(static fn (Role $role): string => $role->name)->all()),
            ];
        }

        $body = Markup::flash($this->flash->take())
            . Html::el('p')->children(Markup::link(self::BASE . '/register', 'Register a group'))->render()
            . Markup::table('Registered groups', ['Name', 'Grouper identifier', 'Active', 'Roles'], $rows, 'No groups are registered yet.');

        return $this->page->response('Groups', $body, $this->crumbs());
    }

    public function registerForm(ServerRequestInterface $request): ResponseInterface
    {
        return $this->registration();
    }

    /**
     * Resolve the typed label to a group. One match registers it; several are
     * offered to choose from; none, or Grouper being unreachable, say so —
     * and say different things, because only one of them is a typo.
     */
    public function register(ServerRequestInterface $request): ResponseInterface
    {
        $label = FormInput::from($request)->string('label');

        if ($label === '') {
            return $this->registration($label, ['Enter the group\'s name as it appears in ACM.'], 422);
        }

        $candidates = $this->grouper->findByLabel($label);

        if ($candidates instanceof GrouperUnavailable) {
            return $this->unavailable($label);
        }

        if ($candidates === []) {
            return $this->registration($label, ["No group labelled \u{201C}{$label}\u{201D} was found. Check the spelling in ACM."], 422);
        }

        if (count($candidates) === 1) {
            return $this->create($candidates[0], $label);
        }

        return $this->choice($label, $candidates);
    }

    /**
     * The administrator picked one of several groups sharing a label. The
     * choice is only accepted if Grouper still offers it, so a tampered form
     * cannot register an arbitrary identifier.
     */
    public function choose(ServerRequestInterface $request): ResponseInterface
    {
        $input = FormInput::from($request);
        $label = $input->string('label');
        $identifier = $input->string('identifier');
        $candidates = $label === '' ? [] : $this->grouper->findByLabel($label);

        if ($candidates instanceof GrouperUnavailable) {
            return $this->unavailable($label);
        }

        foreach ($candidates as $candidate) {
            if ($candidate->identifier === $identifier) {
                return $this->create($candidate, $label);
            }
        }

        return $this->registration($label, ['That group is no longer among the matches. Search again.'], 422);
    }

    /**
     * @param  array<string, string>  $args
     */
    public function edit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $group = $this->find($args);

        return $group === null ? $this->notFound() : $this->editForm($group, $group->name, $group->active, $this->roleIds($group));
    }

    /**
     * @param  array<string, string>  $args
     */
    public function update(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $group = $this->find($args);

        if ($group === null) {
            return $this->notFound();
        }

        $input = FormInput::from($request);
        $name = $input->string('name');
        $active = $input->checked('active');
        $roleIds = $this->validRoleIds($input->strings('roles'));
        $error = $this->nameError($name, $group->id);

        if ($error !== null) {
            return $this->editForm($group, $name, $active, $roleIds, [$error], 422);
        }

        $username = $this->actor->username();

        $group->getConnection()->transaction(function () use ($group, $name, $active, $roleIds, $username): void {
            $group->name = $name;
            $group->active = $active;
            $group->updated_by = $username;
            $group->save();

            $current = $this->roleIds($group);
            $group->roles()->detach(array_values(array_diff($current, $roleIds)));

            foreach (array_diff($roleIds, $current) as $roleId) {
                $group->roles()->attach($roleId, ['created_by' => $username]);
            }
        });

        $this->flash->set("Saved {$name}.");

        return new RedirectResponse(self::BASE . '/' . $group->id, 303);
    }

    /**
     * @param  array<string, string>  $args
     */
    public function confirmDelete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $group = $this->find($args);

        if ($group === null) {
            return $this->notFound();
        }

        $body = Html::el('p')->text(
            "Unregister {$group->name} ({$group->group_identifier})? Its members lose every role it grants here. "
            . 'The group itself, in ACM, is not affected.'
        )->render()
            . new FormBuilder(self::BASE . '/' . $group->id . '/delete', $this->csrf)
                ->render('Unregister group', ButtonPurpose::Danger, self::BASE . '/' . $group->id);

        return $this->page->response('Unregister ' . $group->name, $body, $this->crumbs($group));
    }

    /**
     * @param  array<string, string>  $args
     */
    public function delete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $group = $this->find($args);

        if ($group === null) {
            return $this->notFound();
        }

        // Mappings are detached explicitly rather than left to the foreign
        // key cascade, so the outcome does not depend on the database engine.
        $group->getConnection()->transaction(static function () use ($group): void {
            $group->roles()->detach();
            $group->delete();
        });

        $this->flash->set("Unregistered {$group->name}.");

        return new RedirectResponse(self::BASE, 303);
    }

    private function create(GrouperGroup $candidate, string $label): ResponseInterface
    {
        $existing = Group::query()->where('group_identifier', $candidate->identifier)->first();

        if ($existing instanceof Group) {
            return $this->registration($label, ["{$candidate->identifier} is already registered, as {$existing->name}."], 422);
        }

        // The local name defaults to the ACM label and is freely editable. Two
        // groups can share a label, and the name must be unique, so a clash
        // falls back to the identifier, which is unique by construction.
        $name = $candidate->displayExtension;

        if ($this->nameError($name, null) !== null) {
            $name = "{$candidate->displayExtension} ({$candidate->identifier})";
        }

        $group = new Group();
        $group->name = $name;
        $group->group_identifier = $candidate->identifier;
        $group->active = true;
        $group->created_by = $this->actor->username();
        $group->save();

        $this->flash->set("Registered {$name}. Give it roles below.");

        return new RedirectResponse(self::BASE . '/' . $group->id, 303);
    }

    /**
     * @param  list<string>  $errors
     */
    private function registration(string $label = '', array $errors = [], int $status = 200): ResponseInterface
    {
        $body = Html::el('p')->text(
            'Groups are created and managed in ACM. Registering one here lets you give its members roles in this application.'
        )->render()
            . new FormBuilder(self::BASE . '/register', $this->csrf)
                ->text('label', 'Group name in ACM', $label, $errors, 'Exactly as ACM displays it, for example "My App Editors".')
                ->render('Find group', cancelHref: self::BASE);

        return $this->page->response('Register a group', $body, $this->crumbs(), $status);
    }

    private function unavailable(string $label): ResponseInterface
    {
        return $this->registration($label, [
            'Grouper could not be reached, so the name could not be checked. This does not mean the group is missing. Try again shortly.',
        ], 503);
    }

    /**
     * @param  list<GrouperGroup>  $candidates
     */
    private function choice(string $label, array $candidates): ResponseInterface
    {
        $options = array_map(static fn (GrouperGroup $group): array => [
            'value' => $group->identifier,
            'label' => $group->displayExtension,
            'checked' => false,
            'description' => $group->identifier,
        ], $candidates);

        $body = Html::el('p')->text(
            "More than one group is labelled \u{201C}{$label}\u{201D}. Choose the one you mean by its identifier."
        )->render()
            . new FormBuilder(self::BASE . '/register/choose', $this->csrf)
                ->hidden('label', $label)
                ->radios('identifier', 'Matching groups', $options)
                ->render('Register group', cancelHref: self::BASE);

        return $this->page->response('Choose a group', $body, $this->crumbs());
    }

    /**
     * @param  list<int>  $roleIds
     * @param  list<string>  $errors
     */
    private function editForm(Group $group, string $name, bool $active, array $roleIds, array $errors = [], int $status = 200): ResponseInterface
    {
        $roles = array_values(array_map(static fn (Role $role): array => [
            'value' => (string) $role->id,
            'label' => $role->active ? $role->name : "{$role->name} (inactive)",
            'checked' => in_array($role->id, $roleIds, true),
        ], Role::query()->get()->sortBy('name')->all()));

        $form = new FormBuilder(self::BASE . '/' . $group->id, $this->csrf)
            ->text('name', 'Name', $name, $errors, 'A label for this application only. Renaming it here changes nothing in ACM.')
            ->checkbox('active', 'Active', $active, 'An inactive group grants no roles.');

        $form = $roles === []
            ? $form->html(Html::el('p')->text('No roles exist yet.')->render())
            : $form->checkboxes('roles', 'Roles', $roles, 'Members of this group hold these roles.');

        $body = Markup::flash($this->flash->take())
            . Html::el('p')->text('Grouper identifier: ')->children(Markup::code($group->group_identifier))->render()
            . $form->render('Save', cancelHref: self::BASE)
            . $this->audit($group)
            . Html::el('p')->class('rvt-m-top-xl')->children(Markup::link(self::BASE . '/' . $group->id . '/delete', 'Unregister this group'))->render();

        return $this->page->response($group->name, $body, $this->crumbs($group), $status);
    }

    private function audit(Group $group): string
    {
        $text = sprintf('Registered by %s%s.', $group->created_by, $group->created_at === null ? '' : ' on ' . $group->created_at->format('Y-m-d H:i'));

        if ($group->updated_by !== null) {
            $text .= sprintf(' Last changed by %s%s.', $group->updated_by, $group->updated_at === null ? '' : ' on ' . $group->updated_at->format('Y-m-d H:i'));
        }

        return Html::el('p')->class('rvt-ts-14', 'rvt-color-black-500')->text($text)->render();
    }

    private function nameError(string $name, ?int $exceptId): ?string
    {
        if ($name === '') {
            return 'Enter a name.';
        }

        if (mb_strlen($name) > 255) {
            return 'Use at most 255 characters.';
        }

        $clash = Group::query()->where('name', $name)->first();

        return $clash instanceof Group && $clash->id !== $exceptId
            ? "Another group is already named \u{201C}{$name}\u{201D}."
            : null;
    }

    /**
     * @param  list<string>  $submitted
     * @return list<int>
     */
    private function validRoleIds(array $submitted): array
    {
        $ids = array_map(intval(...), array_filter($submitted, ctype_digit(...)));

        return $ids === [] ? [] : array_values(array_map(
            static fn (Role $role): int => $role->id,
            Role::query()->findMany($ids)->all(),
        ));
    }

    /**
     * @return list<int>
     */
    private function roleIds(Group $group): array
    {
        return array_values(array_map(static fn (Role $role): int => $role->id, $group->roles()->get()->all()));
    }

    /**
     * @param  array<string, string>  $args
     */
    private function find(array $args): ?Group
    {
        $group = Group::query()->find((int) ($args['id'] ?? 0));

        return $group instanceof Group ? $group : null;
    }

    private function notFound(): ResponseInterface
    {
        return $this->page->response('Not found', Html::el('p')->text('There is no such group.')->render(), $this->crumbs(), 404);
    }

    /**
     * @return list<array{label: string, href?: string}>
     */
    private function crumbs(?Group $group = null): array
    {
        $crumbs = [['label' => 'Authorization', 'href' => '/framework/authorization'], ['label' => 'Groups', 'href' => self::BASE]];

        if ($group !== null) {
            $crumbs[] = ['label' => $group->name, 'href' => self::BASE . '/' . $group->id];
        }

        return $crumbs;
    }
}

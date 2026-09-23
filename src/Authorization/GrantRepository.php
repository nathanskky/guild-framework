<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\JoinClause;

/**
 * Resolves Grouper groups to the roles and permissions granted to them.
 *
 * Read fresh on every call. Only Grouper membership is cached; an
 * administrator's change to a mapping takes effect on the next request.
 *
 * @internal
 */
final readonly class GrantRepository
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    /**
     * Active roles mapped to any of the given active groups, and the active
     * permissions those roles hold. A role with no permissions is still listed.
     *
     * @param  list<string>  $groupIdentifiers  Grouper system names.
     * @return array{roles: list<string>, permissions: list<string>}
     */
    public function grantsFor(array $groupIdentifiers): array
    {
        if ($groupIdentifiers === []) {
            return ['roles' => [], 'permissions' => []];
        }

        $rows = $this->connection->table('framework_groups as g')
            ->join('framework_groups_roles as gr', 'gr.group_id', '=', 'g.id')
            ->join('framework_roles as r', 'r.id', '=', 'gr.role_id')
            ->leftJoin('framework_role_permissions as p', static function (JoinClause $join): void {
                $join->on('p.role_id', '=', 'r.id')->where('p.active', '=', true);
            })
            ->where('g.active', '=', true)
            ->where('r.active', '=', true)
            ->whereIn('g.group_identifier', $groupIdentifiers)
            ->get(['r.name as role', 'p.name as permission']);

        $roles = [];
        $permissions = [];

        foreach ($rows as $row) {
            $role = $row->role ?? null;
            $permission = $row->permission ?? null;

            if (is_string($role)) {
                $roles[$role] = true;
            }

            if (is_string($permission)) {
                $permissions[$permission] = true;
            }
        }

        return [
            'roles' => array_map(strval(...), array_keys($roles)),
            'permissions' => array_map(strval(...), array_keys($permissions)),
        ];
    }
}

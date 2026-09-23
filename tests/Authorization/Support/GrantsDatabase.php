<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * An in-memory SQLite copy of the four authorization tables, with helpers to
 * register groups, roles and grants.
 */
final class GrantsDatabase
{
    public readonly Connection $connection;

    public function __construct()
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);

        $connection = $capsule->getConnection();
        assert($connection instanceof Connection);
        $this->connection = $connection;

        $schema = $this->connection->getSchemaBuilder();

        $schema->create('framework_groups', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
            $table->string('group_identifier')->unique();
            $table->boolean('active')->default(false);
        });
        $schema->create('framework_roles', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
            $table->boolean('active')->default(false);
        });
        $schema->create('framework_groups_roles', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('group_id');
            $table->unsignedInteger('role_id');
            $table->unique(['group_id', 'role_id']);
        });
        $schema->create('framework_role_permissions', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('role_id');
            $table->string('name');
            $table->boolean('active')->default(false);
            $table->unique(['role_id', 'name']);
        });
    }

    public function group(string $identifier, bool $active = true): int
    {
        return (int) $this->connection->table('framework_groups')->insertGetId([
            'name' => $identifier,
            'group_identifier' => $identifier,
            'active' => $active,
        ]);
    }

    /**
     * @param  list<string>  $permissions  Granted active.
     */
    public function role(string $name, array $permissions = [], bool $active = true): int
    {
        $roleId = (int) $this->connection->table('framework_roles')->insertGetId([
            'name' => $name,
            'active' => $active,
        ]);

        foreach ($permissions as $permission) {
            $this->grant($roleId, $permission);
        }

        return $roleId;
    }

    public function grant(int $roleId, string $permission, bool $active = true): void
    {
        $this->connection->table('framework_role_permissions')->insert([
            'role_id' => $roleId,
            'name' => $permission,
            'active' => $active,
        ]);
    }

    public function map(int $groupId, int $roleId): void
    {
        $this->connection->table('framework_groups_roles')->insert([
            'group_id' => $groupId,
            'role_id' => $roleId,
        ]);
    }
}

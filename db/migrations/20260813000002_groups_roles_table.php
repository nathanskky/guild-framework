<?php declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class GroupsRolesTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('groups_roles');

        $table
            ->addColumn('group_id', 'integer', [
                'null' => false
            ])
            ->addColumn('role_id', 'integer', [
                'null' => false
            ])
            ->addColumn('created_by', 'string', [
                'null' => false,
                'limit' => 8
            ])
            ->addColumn('updated_by', 'string', [
                'null' => true,
                'default' => null,
                'limit' => 8
            ])
            ->addTimestamps()
            ->create();

        $table
            ->addForeignKey('group_id', 'groups', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE'
            ])
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE'
            ])
            ->save();
    }
}
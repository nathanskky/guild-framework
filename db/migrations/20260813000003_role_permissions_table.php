<?php declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class RolePermissionsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('role_permissions');

        $table
            ->addColumn('role_id', 'integer', [
                'null' => false
            ])
            ->addColumn('name', 'string', [
                'null' => false,
                'limit' => 255
            ])
            ->addColumn('active', 'boolean', [
                'null' => false,
                'default' => false
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
            ->addForeignKey('role_id', 'roles', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE'
            ])
            ->save();
    }
}
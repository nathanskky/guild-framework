<?php declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class GroupsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('groups');

        $table
            ->addColumn('name', 'string', [
                'null' => false,
                'limit' => 255
            ])
            ->addColumn('group_type', 'string', [
                'null' => false,
                'limit' => 255
            ])
            ->addColumn('group_identifier', 'string', [
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
    }
}
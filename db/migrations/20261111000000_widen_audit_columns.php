<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * created_by and updated_by hold a username. IU usernames fit in 8 characters, but an OIDC username
 * or a made-up one from guild/dev-tools ('persona-editor', 'persona-roster') does not.
 */
class WidenAuditColumns extends AbstractMigration
{
    private const array TABLES = ['groups', 'roles', 'groups_roles', 'role_permissions'];

    public function up(): void
    {
        $this->resize(64);
    }

    public function down(): void
    {
        $this->resize(8);
    }

    private function resize(int $limit): void
    {
        foreach (self::TABLES as $name) {
            $this->table($name)
                ->changeColumn('created_by', 'string', ['null' => false, 'limit' => $limit])
                ->changeColumn('updated_by', 'string', ['null' => true, 'default' => null, 'limit' => $limit])
                ->save();
        }
    }
}

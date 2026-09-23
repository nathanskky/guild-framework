<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization;

use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Test\Authorization\Support\GrantsDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GrantRepository::class)]
final class GrantRepositoryTest extends TestCase
{
    private GrantsDatabase $db;

    protected function setUp(): void
    {
        $this->db = new GrantsDatabase();
    }

    public function testGroupsResolveToRolesAndTheirPermissions(): void
    {
        $this->db->map(
            $this->db->group('iu:roles:sys:acm:x-editors'),
            $this->db->role('Editor', ['documents.create', 'documents.update']),
        );

        $grants = $this->repository()->grantsFor(['iu:roles:sys:acm:x-editors']);

        self::assertSame(['Editor'], $grants['roles'], 'the mapped role is listed');
        self::assertSame(['documents.create', 'documents.update'], $grants['permissions'], 'its permissions are listed');
    }

    public function testPermissionsFromSeveralRolesAreDeduplicated(): void
    {
        $group = $this->db->group('iu:roles:sys:acm:x-staff');
        $this->db->map($group, $this->db->role('Editor', ['documents.create']));
        $this->db->map($group, $this->db->role('Author', ['documents.create']));

        $grants = $this->repository()->grantsFor(['iu:roles:sys:acm:x-staff']);

        self::assertSame(['Editor', 'Author'], $grants['roles'], 'both roles are listed');
        self::assertSame(['documents.create'], $grants['permissions'], 'a shared permission is listed once');
    }

    public function testARoleWithNoPermissionsIsStillListed(): void
    {
        $this->db->map($this->db->group('iu:roles:sys:acm:x-viewers'), $this->db->role('Viewer'));

        $grants = $this->repository()->grantsFor(['iu:roles:sys:acm:x-viewers']);

        self::assertSame(['Viewer'], $grants['roles'], 'a role is a role even with nothing granted');
        self::assertSame([], $grants['permissions'], 'and it grants nothing');
    }

    public function testInactiveGroupsRolesAndPermissionsGrantNothing(): void
    {
        $this->db->map($this->db->group('iu:roles:sys:acm:inactive-group', active: false), $this->db->role('A', ['a']));
        $this->db->map($this->db->group('iu:roles:sys:acm:active-group'), $this->db->role('B', ['b'], active: false));
        $roleC = $this->db->role('C');
        $this->db->grant($roleC, 'c', active: false);
        $this->db->map($this->db->group('iu:roles:sys:acm:third-group'), $roleC);

        $grants = $this->repository()->grantsFor([
            'iu:roles:sys:acm:inactive-group',
            'iu:roles:sys:acm:active-group',
            'iu:roles:sys:acm:third-group',
        ]);

        self::assertSame(['C'], $grants['roles'], 'only the active role on an active group counts');
        self::assertSame([], $grants['permissions'], 'an inactive grant grants nothing');
    }

    public function testUnregisteredGroupsGrantNothing(): void
    {
        $this->db->map($this->db->group('iu:roles:sys:acm:x-editors'), $this->db->role('Editor', ['documents.create']));

        $grants = $this->repository()->grantsFor(['iu:roles:sys:acm:somewhere-else']);

        self::assertSame(['roles' => [], 'permissions' => []], $grants, 'a group nobody registered maps to nothing');
    }

    public function testNoGroupsMeansNoQuery(): void
    {
        $this->db->connection->enableQueryLog();

        $grants = $this->repository()->grantsFor([]);

        self::assertSame(['roles' => [], 'permissions' => []], $grants, 'no groups, no grants');
        self::assertSame([], $this->db->connection->getQueryLog(), 'the database is not asked');
    }

    private function repository(): GrantRepository
    {
        return new GrantRepository($this->db->connection);
    }
}

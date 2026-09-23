<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Admin;

use Guild\Framework\Test\Authorization\Support\FakeGrouper;
use Guild\Framework\Test\Authorization\Support\GrantsDatabase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The administration forms end to end. Every request is made by jdoe, a
 * member of the System Admin group; the first Grouper response answers that
 * check, and any after it answer label searches.
 *
 * Integration coverage across many classes, so it declares none.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdministrationFormsTest extends TestCase
{
    use DispatchesAdministration;

    private const string STEM = 'iu:roles:sys:acm:';

    // -- registering a group -----------------------------------------------

    public function testOneMatchRegistersTheGroupUnderItsAcmLabel(): void
    {
        [$response, $db] = $this->post('/framework/authorization/groups/register', ['label' => 'X Editors'], [
            FakeGrouper::labelMatches([self::STEM . 'x-editors' => 'X Editors']),
        ]);

        $group = $db->connection->table('framework_groups')->first();

        self::assertSame(303, $response->getStatusCode(), 'post/redirect/get');
        self::assertNotNull($group, 'the group is registered');
        self::assertSame(self::STEM . 'x-editors', $group->group_identifier, 'by the identifier Grouper resolved');
        self::assertSame('X Editors', $group->name, 'named after its ACM label');
        self::assertSame(1, (int) $group->active, 'and active, because registering it means it to work');
        self::assertSame('jdoe', $group->created_by, 'audited to the administrator');
        self::assertSame('/framework/authorization/groups/' . $group->id, $response->getHeaderLine('Location'), 'on to give it roles');
    }

    public function testNoMatchIsReportedAsProbablyATypo(): void
    {
        [$response, $db] = $this->post('/framework/authorization/groups/register', ['label' => 'X Editorz'], [FakeGrouper::labelMatches([])]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('No group labelled', (string) $response->getBody());
        self::assertSame(0, $db->connection->table('framework_groups')->count(), 'nothing is registered');
    }

    public function testUnreachableGrouperIsNotReportedAsAMissingGroup(): void
    {
        [$response] = $this->post('/framework/authorization/groups/register', ['label' => 'X Editors'], [FakeGrouper::unavailable()]);
        $html = (string) $response->getBody();

        self::assertSame(503, $response->getStatusCode(), '"could not ask" is not "no such group"');
        self::assertStringContainsString('does not mean the group is missing', $html);
        self::assertStringNotContainsString('No group labelled', $html);
    }

    public function testSeveralMatchesAreOfferedByIdentifier(): void
    {
        [$response, $db] = $this->post('/framework/authorization/groups/register', ['label' => 'Editors'], [
            FakeGrouper::labelMatches([self::STEM . 'x-editors' => 'Editors', self::STEM . 'y-editors' => 'Editors']),
        ]);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('value="' . self::STEM . 'x-editors"', $html, 'each candidate is a choice');
        self::assertStringContainsString('value="' . self::STEM . 'y-editors"', $html);
        self::assertSame(0, $db->connection->table('framework_groups')->count(), 'nothing is registered until one is chosen');
    }

    public function testAChoiceGrouperStillOffersIsRegistered(): void
    {
        $matches = FakeGrouper::labelMatches([self::STEM . 'x-editors' => 'Editors', self::STEM . 'y-editors' => 'Editors']);

        [$chosen, $db] = $this->post('/framework/authorization/groups/register/choose', [
            'label' => 'Editors',
            'identifier' => self::STEM . 'y-editors',
        ], [$matches]);

        self::assertSame(303, $chosen->getStatusCode(), 'a genuine choice registers');
        self::assertSame(self::STEM . 'y-editors', $db->connection->table('framework_groups')->value('group_identifier'));
    }

    public function testAChoiceGrouperNoLongerOffersIsRefused(): void
    {
        [$tampered, $db] = $this->post('/framework/authorization/groups/register/choose', [
            'label' => 'Editors',
            'identifier' => 'iu:roles:sys:acm:anything-i-like',
        ], [FakeGrouper::labelMatches([self::STEM . 'x-editors' => 'Editors'])]);

        self::assertSame(422, $tampered->getStatusCode(), 'an identifier Grouper did not offer is refused');
        self::assertSame(0, $db->connection->table('framework_groups')->count());
    }

    public function testAGroupCannotBeRegisteredTwice(): void
    {
        [$response, $db] = $this->post('/framework/authorization/groups/register', ['label' => 'X Editors'], [
            FakeGrouper::labelMatches([self::STEM . 'x-editors' => 'X Editors']),
        ], static function (GrantsDatabase $db): void {
            $db->group(self::STEM . 'x-editors');
        });

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('is already registered', (string) $response->getBody());
        self::assertSame(1, $db->connection->table('framework_groups')->count());
    }

    public function testASharedLabelFallsBackToANameIncludingTheIdentifier(): void
    {
        [$response, $db] = $this->post('/framework/authorization/groups/register', ['label' => 'Editors'], [
            FakeGrouper::labelMatches([self::STEM . 'y-editors' => 'Editors']),
        ], static function (GrantsDatabase $db): void {
            $db->connection->table('framework_groups')->insert(['name' => 'Editors', 'group_identifier' => self::STEM . 'x-editors', 'active' => true]);
        });

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            'Editors (' . self::STEM . 'y-editors)',
            $db->connection->table('framework_groups')->where('group_identifier', self::STEM . 'y-editors')->value('name'),
            'names are unique, identifiers more so',
        );
    }

    // -- editing a group ---------------------------------------------------

    public function testEditingAGroupRenamesItAndSetsItsRoles(): void
    {
        $ids = [];

        [$response, $db] = $this->post('/framework/authorization/groups/1', [
            'name' => 'Editors of X',
            'roles' => ['2'],
        ], [], static function (GrantsDatabase $db) use (&$ids): void {
            $group = $db->group(self::STEM . 'x-editors');
            $db->map($group, $db->role('Editor'));
            $db->role('Author');
        });

        $group = $db->connection->table('framework_groups')->first();
        $mappings = $db->connection->table('framework_groups_roles')->get();

        self::assertSame(303, $response->getStatusCode());
        self::assertNotNull($group);
        self::assertSame('Editors of X', $group->name, 'renamed');
        self::assertSame(0, (int) $group->active, 'an unchecked box deactivates');
        self::assertSame('jdoe', $group->updated_by, 'audited');
        self::assertCount(1, $mappings, 'Editor was removed and Author added');
        self::assertSame(2, (int) $mappings[0]->role_id);
        self::assertSame('jdoe', $mappings[0]->created_by, 'the new mapping is audited');
    }

    public function testAGroupCannotTakeAnotherGroupsName(): void
    {
        [$response, $db] = $this->post('/framework/authorization/groups/1', ['name' => 'Taken', 'active' => '1'], [], static function (GrantsDatabase $db): void {
            $db->group(self::STEM . 'x-editors');
            $db->connection->table('framework_groups')->insert(['name' => 'Taken', 'group_identifier' => self::STEM . 'y', 'active' => true]);
        });

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Another group is already named', (string) $response->getBody());
        self::assertSame(self::STEM . 'x-editors', $db->connection->table('framework_groups')->where('id', 1)->value('name'), 'unchanged');
    }

    public function testUnregisteringAGroupRemovesItsMappings(): void
    {
        [$response, $db] = $this->post('/framework/authorization/groups/1/delete', [], [], static function (GrantsDatabase $db): void {
            $db->map($db->group(self::STEM . 'x-editors'), $db->role('Editor'));
        });

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(0, $db->connection->table('framework_groups')->count());
        self::assertSame(0, $db->connection->table('framework_groups_roles')->count(), 'no mapping outlives its group');
        self::assertSame(1, $db->connection->table('framework_roles')->count(), 'the role itself remains');
    }

    public function testAnUnknownGroupIs404(): void
    {
        [$response] = $this->dispatch('GET', '/framework/authorization/groups/99', 'jdoe', $this->admin());

        self::assertSame(404, $response->getStatusCode());
    }

    // -- roles -------------------------------------------------------------

    public function testCreatingARoleGrantsOnlyDefinedPermissions(): void
    {
        [$response, $db] = $this->post('/framework/authorization/roles/new', [
            'name' => 'Editor',
            'active' => '1',
            'permissions' => ['documents.update', 'documents.invented'],
        ]);

        $grants = $db->connection->table('framework_role_permissions')->pluck('name')->all();

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(['documents.update'], $grants, 'a name the application does not define cannot be granted');
        self::assertSame('jdoe', $db->connection->table('framework_roles')->value('created_by'));
    }

    public function testEditingARoleSyncsItsGrantsAndOrphans(): void
    {
        [$response, $db] = $this->post('/framework/authorization/roles/1', [
            'name' => 'Editor',
            'active' => '1',
            'permissions' => ['documents.view'],
            'orphans' => ['documents.retired', 'documents.smuggled'],
        ], [], static function (GrantsDatabase $db): void {
            $db->role('Editor', ['documents.update', 'documents.retired', 'documents.gone']);
        });

        $grants = $db->connection->table('framework_role_permissions')->orderBy('name')->pluck('name')->all();

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            ['documents.retired', 'documents.view'],
            $grants,
            'unchecked grants and orphans are deleted, a kept orphan stays, and the orphan list grants nothing new',
        );
    }

    public function testDeletingARoleRemovesItsGrantsAndMappings(): void
    {
        [$response, $db] = $this->post('/framework/authorization/roles/1/delete', [], [], static function (GrantsDatabase $db): void {
            $db->map($db->group(self::STEM . 'x-editors'), $db->role('Editor', ['documents.update']));
        });

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(0, $db->connection->table('framework_roles')->count());
        self::assertSame(0, $db->connection->table('framework_role_permissions')->count());
        self::assertSame(0, $db->connection->table('framework_groups_roles')->count());
        self::assertSame(1, $db->connection->table('framework_groups')->count(), 'the group remains registered');
    }

    public function testTheRoleFormOffersEveryPermissionAndItsOrphans(): void
    {
        [$response] = $this->dispatch('GET', '/framework/authorization/roles/1', 'jdoe', $this->admin(), static function (GrantsDatabase $db): void {
            $db->role('Editor', ['documents.update', 'documents.retired']);
        });
        $html = (string) $response->getBody();

        self::assertStringContainsString('value="documents.create"', $html, 'every case is offered');
        self::assertMatchesRegularExpression('/value="documents.update" checked/', $html, 'a held grant is checked');
        self::assertStringContainsString('No longer defined by the application', $html, 'orphans are listed separately');
    }

    // -- protection --------------------------------------------------------

    public function testAPostWithoutTheSessionsTokenChangesNothing(): void
    {
        [$response, $db] = $this->dispatch('POST', '/framework/authorization/roles/new', 'jdoe', $this->admin(), form: ['name' => 'Editor'], csrf: false);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $db->connection->table('framework_roles')->count());
    }

    public function testAnOrdinaryUsersPostChangesNothing(): void
    {
        [$response, $db] = $this->dispatch('POST', '/framework/authorization/roles/new', 'jdoe', [
            FakeGrouper::membership(['iu:x-editors' => 'X Editors']),
        ], form: ['name' => 'Editor']);

        self::assertSame(403, $response->getStatusCode(), 'the gate runs before the form');
        self::assertSame(0, $db->connection->table('framework_roles')->count());
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  list<\\Psr\\Http\\Message\\ResponseInterface>  $grouperAfterAdmin
     * @param  (callable(GrantsDatabase): void)|null  $seed
     * @return array{\\Psr\\Http\\Message\\ResponseInterface, GrantsDatabase}
     */
    private function post(string $path, array $form, array $grouperAfterAdmin = [], ?callable $seed = null): array
    {
        return $this->dispatch('POST', $path, 'jdoe', [...$this->admin(), ...$grouperAfterAdmin], $seed, form: $form);
    }
}

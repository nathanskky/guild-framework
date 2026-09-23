<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization;

use Guild\Framework\Authorization\AdminMenu;
use Guild\Framework\Authorization\AdminNavigation;
use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Membership\Membership;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Membership\Session;
use Guild\Framework\Authorization\User;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Test\Authorization\Support\FakeGrouper;
use Guild\Framework\Test\Authorization\Support\GateFactory;
use Guild\Framework\Test\Authorization\Support\GrantsDatabase;
use Guild\Rivet\Page\PageDefaults;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Membership is cached in the session, so each test runs in its own process.
 */
#[CoversClass(AdminNavigation::class)]
#[CoversClass(AdminMenu::class)]
#[UsesClass(User::class)]
#[UsesClass(UserResolver::class)]
#[UsesClass(Identity::class)]
#[UsesClass(GrantRepository::class)]
#[UsesClass(Membership::class)]
#[UsesClass(MembershipCache::class)]
#[UsesClass(MembershipProvider::class)]
#[UsesClass(Session::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminNavigationTest extends TestCase
{
    public function testASystemAdminSeesTheMenuLastByDefault(): void
    {
        $items = $this->itemsFor(['iu:x-admins' => 'My App Admins']);

        self::assertSame(['Courses', 'People', 'System settings'], array_column($items, 'label'), 'appended after the application\'s items');
        self::assertSame(
            [['label' => 'Authorization', 'href' => '/framework/authorization']],
            $items[2]['children'] ?? null,
            'the menu leads to the framework\'s pages',
        );
    }

    public function testTheApplicationChoosesLabelAndPosition(): void
    {
        $items = $this->itemsFor(['iu:x-admins' => 'My App Admins'], new AdminMenu('Admin', position: 0));

        self::assertSame(['Admin', 'Courses', 'People'], array_column($items, 'label'), 'placed first under its own label');
    }

    public function testEveryoneElseSeesTheApplicationsItemsUnchanged(): void
    {
        self::assertSame($this->defaults()->navItems, $this->itemsFor(['iu:x-editors' => 'X Editors']), 'not a system admin');
        self::assertSame($this->defaults()->navItems, $this->navigation(null, [])->navItems($this->defaults()), 'a guest');
    }

    public function testAnUnanswerableCheckLeavesTheMenuOutRatherThanThrowing(): void
    {
        $navigation = $this->navigation(new Identity('jdoe'), [FakeGrouper::unavailable()]);

        self::assertSame($this->defaults()->navItems, $navigation->navItems($this->defaults()), 'the page still renders');
    }

    public function testTheMenuIsCurrentUnderFramework(): void
    {
        $under = $this->itemsFor(['iu:x-admins' => 'My App Admins'], path: '/framework/authorization/groups');
        $beside = $this->itemsFor(['iu:x-admins' => 'My App Admins'], path: '/frameworks');

        self::assertTrue($under[2]['current'] ?? null, 'a framework page marks the menu current');
        self::assertFalse($beside[2]['current'] ?? null, 'a path that merely starts with the same letters does not');
    }

    public function testABlankLabelIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new AdminMenu('  ');
    }

    /**
     * @param  array<string, string>  $groups
     * @return list<array<string, mixed>>
     */
    private function itemsFor(array $groups, AdminMenu $menu = new AdminMenu(), string $path = '/'): array
    {
        return $this->navigation(new Identity('jdoe'), [FakeGrouper::membership($groups)], $menu, $path)
            ->navItems($this->defaults());
    }

    /**
     * @param  list<\Psr\Http\Message\ResponseInterface>  $grouperResponses
     */
    private function navigation(?Identity $identity, array $grouperResponses, AdminMenu $menu = new AdminMenu(), string $path = '/'): AdminNavigation
    {
        return new AdminNavigation(
            GateFactory::resolver(new FakeGrouper($grouperResponses), $identity, new GrantsDatabase()),
            $menu,
            new ServerRequest(uri: $path),
        );
    }

    private function defaults(): PageDefaults
    {
        return new PageDefaults(appTitle: 'Course Catalog', navItems: [
            ['label' => 'Courses', 'href' => '/courses'],
            ['label' => 'People', 'href' => '/people'],
        ]);
    }
}

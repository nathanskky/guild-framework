<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization;

use Guild\Framework\Testing\ArraySessionStore;
use Guild\Framework\Authorization\Membership\GrouperGroupDirectory;
use Guild\Framework\Authorization\Gate;
use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Handles;
use Guild\Framework\Authorization\HandlesResource;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Policy\PolicyMethod;
use Guild\Framework\Authorization\Policy\PolicyRegistry;
use Guild\Framework\Authorization\TemplateAuthorization;
use Guild\Framework\Authorization\User;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Test\Authorization\Support\GateFactory;
use Guild\Framework\Test\Authorization\Support\GrantsDatabase;
use Guild\Framework\Test\Authorization\Support\Policy\Document;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Guild\Grouper\Testing\FakeGrouper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Checks run as a guest against DocumentPolicy, whose view() accepts ?User,
 * so nothing touches the session.
 */
#[CoversClass(TemplateAuthorization::class)]
#[UsesClass(Gate::class)]
#[UsesClass(UserResolver::class)]
#[UsesClass(User::class)]
#[UsesClass(Identity::class)]
#[UsesClass(PolicyRegistry::class)]
#[UsesClass(PolicyMethod::class)]
#[UsesClass(Handles::class)]
#[UsesClass(HandlesResource::class)]
#[UsesClass(MembershipProvider::class)]
#[UsesClass(MembershipCache::class)]
#[UsesClass(GrantRepository::class)]
#[UsesClass(GrouperGroupDirectory::class)]
#[UsesClass(ArraySessionStore::class)]
final class TemplateAuthorizationTest extends TestCase
{
    public function testAPermissionCanBeNamedByCaseOrByValue(): void
    {
        $authorization = $this->authorization();
        $public = new Document('jdoe', public: true);

        self::assertTrue($authorization->can(TestPermission::DocumentsView, $public), 'by case');
        self::assertTrue($authorization->can('documents.view', $public), 'by stored value');
        self::assertTrue($authorization->cannot('documents.update'), 'cannot is the negation');
    }

    public function testAnUnknownValueThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("'documents.updat' is not a permission");

        $this->authorization()->can('documents.updat');
    }

    public function testTheGateIsResolvedOnFirstUse(): void
    {
        $resolutions = 0;
        $authorization = new TemplateAuthorization(static function () use (&$resolutions): Gate {
            $resolutions++;

            return GateFactory::guest();
        }, static fn (): UserResolver => self::fail('current_user() was not called'), TestPermission::class);

        self::assertSame(0, $resolutions, 'a page that never checks costs nothing');

        $authorization->can('documents.update');
        $authorization->can('documents.create');

        self::assertSame(1, $resolutions, 'and the Gate is resolved once');
    }

    public function testTheUserResolverIsNotResolvedUntilCurrentUserIsCalled(): void
    {
        $resolutions = 0;
        $db = new GrantsDatabase();
        $db->map($db->group('iu:x-readers'), $db->role('Reader', ['documents.view']));
        $jdoe = new Identity('jdoe');
        $authorization = new TemplateAuthorization(
            static fn (): Gate => GateFactory::make(new FakeGrouper([FakeGrouper::membership(['iu:x-readers' => 'X Readers'])]), $jdoe, $db),
            static function () use (&$resolutions, $jdoe, $db): UserResolver {
                $resolutions++;

                return GateFactory::resolver(new FakeGrouper([]), $jdoe, $db);
            },
            TestPermission::class,
        );

        self::assertSame(0, $resolutions, 'a page that never asks for the user costs nothing');
        self::assertTrue($authorization->can('documents.view', new Document('asmith', public: true)), 'can() answers for jdoe, a reader, without it');
        self::assertSame(0, $resolutions, 'checking a permission leaves the resolver alone');

        self::assertSame('jdoe', $authorization->currentUser()?->username(), 'the signed-in user');
        $authorization->currentUser();

        self::assertSame(1, $resolutions, 'and the resolver is resolved once');
    }

    private function authorization(): TemplateAuthorization
    {
        return new TemplateAuthorization(
            GateFactory::guest(...),
            static fn (): UserResolver => self::fail('current_user() was not called'),
            TestPermission::class,
        );
    }
}

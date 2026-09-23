<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization;

use Guild\Framework\Authorization\Gate;
use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Handles;
use Guild\Framework\Authorization\HandlesResource;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Policy\PolicyMethod;
use Guild\Framework\Authorization\Policy\PolicyRegistry;
use Guild\Framework\Authorization\TemplateAuthorization;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Test\Authorization\Support\GateFactory;
use Guild\Framework\Test\Authorization\Support\Policy\Document;
use Guild\Framework\Test\Authorization\Support\TestPermission;
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
#[UsesClass(PolicyRegistry::class)]
#[UsesClass(PolicyMethod::class)]
#[UsesClass(Handles::class)]
#[UsesClass(HandlesResource::class)]
#[UsesClass(MembershipProvider::class)]
#[UsesClass(MembershipCache::class)]
#[UsesClass(GrantRepository::class)]
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
        }, TestPermission::class);

        self::assertSame(0, $resolutions, 'a page that never checks costs nothing');

        $authorization->can('documents.update');
        $authorization->can('documents.create');

        self::assertSame(1, $resolutions, 'and the Gate is resolved once');
    }

    private function authorization(): TemplateAuthorization
    {
        return new TemplateAuthorization(GateFactory::guest(...), TestPermission::class);
    }
}

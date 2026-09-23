<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Support;

use Guild\Framework\Authorization\Gate;
use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Identity\IdentityReader;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Membership\Session;
use Guild\Framework\Authorization\Policy\PolicyRegistry;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Test\Authorization\Support\Policy\DocumentPolicy;
use League\Container\Container;
use League\Container\ReflectionContainer;
use Psr\Log\NullLogger;

/**
 * A real Gate, or UserResolver, over a fake Grouper and the in-memory grants database, with
 * DocumentPolicy registered and 'My App Admins' as the System Admin label.
 */
final class GateFactory
{
    public static function make(FakeGrouper $grouper, ?Identity $identity, GrantsDatabase $db, int $now = 100_000): Gate
    {
        $container = new Container();
        $container->delegate(new ReflectionContainer());

        return new Gate(
            self::resolver($grouper, $identity, $db, $now),
            new PolicyRegistry($container, [DocumentPolicy::class], TestPermission::class),
            TestPermission::class,
        );
    }

    public static function resolver(FakeGrouper $grouper, ?Identity $identity, GrantsDatabase $db, int $now = 100_000): UserResolver
    {
        $reader = new class ($identity) implements IdentityReader {
            public function __construct(private readonly ?Identity $identity)
            {
            }

            public function read(): ?Identity
            {
                return $this->identity;
            }
        };

        return new UserResolver(
            $reader,
            new MembershipProvider(
                $grouper->client,
                new MembershipCache(new Session()),
                new NullLogger(),
                900,
                3600,
                static fn (): int => $now,
            ),
            new GrantRepository($db->connection),
            TestPermission::class,
            'My App Admins',
        );
    }

    /**
     * A Gate for a guest. Guest checks never touch the session or Grouper.
     */
    public static function guest(): Gate
    {
        return self::make(new FakeGrouper([]), null, new GrantsDatabase());
    }
}

<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization;

use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Identity\IdentityReader;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Membership\Session;
use Guild\Framework\Authorization\User;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Test\Authorization\Support\FakeGrouper;
use Guild\Framework\Test\Authorization\Support\GrantsDatabase;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(UserResolver::class)]
#[UsesClass(User::class)]
#[UsesClass(Identity::class)]
#[UsesClass(GrantRepository::class)]
#[UsesClass(MembershipCache::class)]
#[UsesClass(MembershipProvider::class)]
#[UsesClass(Session::class)]
final class UserResolverTest extends TestCase
{
    public function testAnIdentityResolvesToTheSameUserEveryTime(): void
    {
        $reader = $this->reader(new Identity('jdoe'));
        $resolver = $this->resolver($reader);

        $user = $resolver->current();

        self::assertNotNull($user, 'an identity is a user');
        self::assertSame('jdoe', $user->username(), 'for that username');
        self::assertSame($user, $resolver->current(), 'every caller shares one User');
        self::assertSame(1, $reader->reads, 'the identity is read once');
    }

    public function testAGuestIsNullAndIsAlsoResolvedOnce(): void
    {
        $reader = $this->reader(null);
        $resolver = $this->resolver($reader);

        self::assertNull($resolver->current(), 'no identity is a guest');
        self::assertNull($resolver->current(), 'still a guest');
        self::assertSame(1, $reader->reads, 'a guest is not re-read on every call');
    }

    private function reader(?Identity $identity): IdentityReader
    {
        return new class ($identity) implements IdentityReader {
            public int $reads = 0;

            public function __construct(private readonly ?Identity $identity)
            {
            }

            public function read(): ?Identity
            {
                $this->reads++;

                return $this->identity;
            }
        };
    }

    private function resolver(IdentityReader $reader): UserResolver
    {
        return new UserResolver(
            $reader,
            new MembershipProvider(new FakeGrouper([])->client, new MembershipCache(new Session()), new NullLogger(), 900, 3600, time(...)),
            new GrantRepository(new GrantsDatabase()->connection),
            TestPermission::class,
            'My App Admins',
        );
    }
}

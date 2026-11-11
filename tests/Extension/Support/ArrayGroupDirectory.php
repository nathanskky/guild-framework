<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Extension\Support;

use Guild\Framework\Authorization\Membership\GroupDirectory;
use Guild\Grouper\GroupMembership;
use Guild\Grouper\GrouperGroup;
use Guild\Grouper\GrouperUnavailable;

/**
 * A group directory for tests: each username maps to the groups it belongs
 * to, and an unknown username belongs to nothing. It is always available.
 */
final readonly class ArrayGroupDirectory implements GroupDirectory
{
    /**
     * @param  array<string, list<GrouperGroup>>  $groups
     */
    public function __construct(private array $groups = [])
    {
    }

    #[\Override]
    public function groupsFor(string $username): GroupMembership|GrouperUnavailable
    {
        return new GroupMembership($username, $this->groups[$username] ?? []);
    }
}

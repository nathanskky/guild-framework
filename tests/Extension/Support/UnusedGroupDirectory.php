<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Extension\Support;

use Guild\Framework\Authorization\Membership\GroupDirectory;
use Guild\Grouper\GroupMembership;
use Guild\Grouper\GrouperUnavailable;
use LogicException;

/**
 * A group directory for tests that only need a class name to choose.
 */
final class UnusedGroupDirectory implements GroupDirectory
{
    #[\Override]
    public function groupsFor(string $username): GroupMembership|GrouperUnavailable
    {
        throw new LogicException('UnusedGroupDirectory is chosen in tests but never asked.');
    }
}

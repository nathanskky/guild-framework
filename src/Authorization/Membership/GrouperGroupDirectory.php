<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Membership;

use Guild\Grouper\GroupMembership;
use Guild\Grouper\GrouperClient;
use Guild\Grouper\GrouperUnavailable;
use Override;

/**
 * Groups from live Grouper.
 *
 * Errors a person must fix, such as rejected credentials, are thrown by
 * guild/grouper and pass through unchanged.
 *
 * @internal
 */
final readonly class GrouperGroupDirectory implements GroupDirectory
{
    public function __construct(private GrouperClient $grouper)
    {
    }

    #[Override]
    public function groupsFor(string $username): GroupMembership|GrouperUnavailable
    {
        return $this->grouper->groupsFor($username);
    }
}

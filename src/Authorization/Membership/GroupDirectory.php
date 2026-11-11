<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Membership;

use Guild\Grouper\GroupMembership;
use Guild\Grouper\GrouperUnavailable;

/**
 * Where a user's groups come from.
 *
 * The answer uses guild/grouper's types because group identity here is
 * Grouper identity: every implementation names real Grouper system names, so
 * the same grants work whichever directory answers. Every implementation must
 * pass Testing\GroupDirectoryContract.
 */
interface GroupDirectory
{
    /**
     * The groups $username belongs to. An unknown username is an empty
     * membership; GrouperUnavailable means the answer is unknown right now.
     */
    public function groupsFor(string $username): GroupMembership|GrouperUnavailable;
}

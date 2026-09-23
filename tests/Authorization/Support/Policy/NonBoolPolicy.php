<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Support\Policy;

use Guild\Framework\Authorization\Handles;
use Guild\Framework\Authorization\HandlesResource;
use Guild\Framework\Authorization\User;
use Guild\Framework\Test\Authorization\Support\TestPermission;

#[HandlesResource(Invoice::class)]
final class NonBoolPolicy
{
    #[Handles(TestPermission::InvoicesApprove)]
    public function approve(User $user, Invoice $invoice): int
    {
        return 1;
    }
}

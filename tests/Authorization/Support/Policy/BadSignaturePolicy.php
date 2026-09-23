<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Support\Policy;

use Guild\Framework\Authorization\Handles;
use Guild\Framework\Authorization\HandlesResource;
use Guild\Framework\Test\Authorization\Support\TestPermission;

#[HandlesResource(Invoice::class)]
final class BadSignaturePolicy
{
    #[Handles(TestPermission::InvoicesApprove)]
    public function approve(Invoice $invoice): bool
    {
        return true;
    }
}

<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Support\Policy;

use Guild\Framework\Authorization\Handles;
use Guild\Framework\Authorization\HandlesResource;
use Guild\Framework\Authorization\User;
use Guild\Framework\Test\Authorization\Support\OtherPermission;

#[HandlesResource(Invoice::class)]
final class WrongEnumPolicy
{
    #[Handles(OtherPermission::DocumentsCreate)]
    public function create(User $user, Invoice $invoice): bool
    {
        return true;
    }
}

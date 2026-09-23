<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Support\Policy;

use Guild\Framework\Authorization\Handles;
use Guild\Framework\Authorization\HandlesResource;
use Guild\Framework\Authorization\User;
use Guild\Framework\Test\Authorization\Support\TestPermission;

#[HandlesResource(Document::class)]
final class DocumentPolicy
{
    public static int $constructed = 0;

    public static int $calls = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    #[Handles(TestPermission::DocumentsUpdate)]
    public function update(User $user, Document $document): bool
    {
        self::$calls++;

        return $document->owner === $user->username();
    }

    #[Handles(TestPermission::DocumentsView)]
    public function view(?User $user, Document $document): bool
    {
        self::$calls++;

        return $document->public || $document->owner === $user?->username();
    }
}

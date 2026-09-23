<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Identity;

interface IdentityReader
{
    /**
     * The identity behind the current request, or null for a guest.
     */
    public function read(): ?Identity;
}

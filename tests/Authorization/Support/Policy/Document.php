<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Support\Policy;

final class Document
{
    public function __construct(public string $owner, public bool $public = false)
    {
    }
}

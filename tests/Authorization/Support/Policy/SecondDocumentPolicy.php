<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Support\Policy;

use Guild\Framework\Authorization\HandlesResource;

#[HandlesResource(Document::class)]
final class SecondDocumentPolicy
{
}

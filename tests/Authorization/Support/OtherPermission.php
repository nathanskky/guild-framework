<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Support;

use Guild\Framework\Authorization\Permission;

enum OtherPermission: string implements Permission
{
    case DocumentsCreate = 'documents.create';
}

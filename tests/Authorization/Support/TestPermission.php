<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Support;

use Guild\Framework\Authorization\Permission;

enum TestPermission: string implements Permission
{
    case DocumentsCreate = 'documents.create';
    case DocumentsUpdate = 'documents.update';
    case InvoicesApprove = 'invoices.approve';
}

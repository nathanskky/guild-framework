<?php

declare(strict_types=1);

namespace Guild\Framework\Latte;

use Guild\Framework\Authorization\TemplateAuthorization;
use Latte\Extension;

/**
 * can() and cannot() for Latte templates:
 *
 *     {if can('documents.update', $document)}…{/if}
 */
final class AuthorizationExtension extends Extension
{
    public function __construct(private readonly TemplateAuthorization $authorization)
    {
    }

    public function getFunctions(): array
    {
        return [
            'can' => $this->authorization->can(...),
            'cannot' => $this->authorization->cannot(...),
        ];
    }
}

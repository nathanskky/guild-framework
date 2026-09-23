<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Identity;

/**
 * Who is making the request, as reported by the identity source.
 */
final readonly class Identity
{
    /**
     * @param  string  $username  The IU username.
     * @param  array<string, mixed>  $attributes  OIDC userinfo claims. Always empty under CAS.
     */
    public function __construct(
        public string $username,
        public array $attributes = [],
    ) {
    }
}

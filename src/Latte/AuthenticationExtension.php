<?php

declare(strict_types=1);

namespace Guild\Framework\Latte;

use Guild\Framework\Authentication\SignOutForm;
use Latte\Extension;
use Latte\Runtime\Html;

/**
 * sign_out_form() for Latte templates: the sign-out button and its CSRF-protected
 * form, as markup:
 *
 *     {sign_out_form()}
 */
final class AuthenticationExtension extends Extension
{
    public function __construct(private readonly SignOutForm $signOutForm)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            'sign_out_form' => fn (): Html => new Html($this->signOutForm->render()),
        ];
    }
}

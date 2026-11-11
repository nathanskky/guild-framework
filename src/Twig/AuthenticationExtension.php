<?php

declare(strict_types=1);

namespace Guild\Framework\Twig;

use Guild\Framework\Authentication\SignOutForm;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * sign_out_form() for Twig templates: the sign-out button and its CSRF-protected
 * form, as markup:
 *
 *     {{ sign_out_form() }}
 *
 * @internal Built only from the internal SignOutForm.
 */
final class AuthenticationExtension extends AbstractExtension
{
    public function __construct(private readonly SignOutForm $signOutForm)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('sign_out_form', $this->signOutForm->render(...), ['is_safe' => ['html']]),
        ];
    }
}

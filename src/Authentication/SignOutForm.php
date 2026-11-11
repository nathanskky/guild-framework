<?php

declare(strict_types=1);

namespace Guild\Framework\Authentication;

use Closure;
use Guild\Framework\Admin\Csrf;
use Guild\Rivet\Html\Html;

/**
 * The sign-out button as a form that carries the session's CSRF token, for
 * the sign_out_form() template function.
 *
 * Takes the token source as a closure so building the form does not start a
 * session until a template actually renders it.
 *
 * @internal
 */
final readonly class SignOutForm
{
    /**
     * @param  Closure(): Csrf  $resolveCsrf
     */
    public function __construct(private Closure $resolveCsrf)
    {
    }

    public function render(): string
    {
        return Html::el('form')
            ->attr('method', 'post')
            ->attr('action', '/framework/sign-out')
            ->children(
                ($this->resolveCsrf)()->field(),
                Html::el('button')->attr('type', 'submit')->class('rvt-button', 'rvt-button--secondary')->text('Sign out'),
            )
            ->render();
    }
}

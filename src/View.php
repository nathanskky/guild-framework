<?php

declare(strict_types=1);

namespace Guild\Framework;

use Guild\Framework\Exception\AuthorizationUnavailableException;
use Latte\Engine;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

readonly class View
{
    public function __construct(public Environment|Engine $templateEngine)
    {
    }

    /**
     * An AuthorizationUnavailableException raised while rendering, typically
     * from can() in a template, is rethrown as itself under both engines. Twig
     * would otherwise wrap it in a RuntimeError, and an application that
     * renders "could not ask" as a 503 would never see it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     * @throws AuthorizationUnavailableException
     */
    public function render(string $view, array $data = []): string
    {
        if ($this->templateEngine instanceof Engine) {
            return $this->templateEngine->renderToString($view, $data);
        }

        try {
            return $this->templateEngine->render($view, $data);
        } catch (RuntimeError $error) {
            $previous = $error->getPrevious();

            throw $previous instanceof AuthorizationUnavailableException ? $previous : $error;
        }
    }
}

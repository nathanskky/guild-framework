<?php declare(strict_types=1);

namespace Guild\Framework;

use Latte\Engine;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

readonly class View
{
    public function __construct(public Environment|Engine $templateEngine) {}

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function render(string $view, array $data = []): string
    {
        return match (true) {
            $this->templateEngine instanceof Environment => $this->templateEngine->render($view, $data),
            $this->templateEngine instanceof Engine => $this->templateEngine->renderToString($view, $data),
        };
    }
}

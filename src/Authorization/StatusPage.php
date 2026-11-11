<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use Closure;
use Guild\Rivet\Component\Page\Page;
use Guild\Rivet\Html\Html;
use Guild\Rivet\Render\Renderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * A short page that tells a visitor why a request was not answered: no such
 * page, the wrong method, or an authorization failure.
 *
 * With addRivet() it is an rvt_page document in the application's layout;
 * without it, a plain HTML5 document.
 *
 * @internal
 */
final readonly class StatusPage
{
    /**
     * @param  Closure(): ?Renderer  $resolveRenderer  The application's Renderer, or null when Rivet was not added.
     */
    public function __construct(private Closure $resolveRenderer)
    {
    }

    public function response(string $title, string $message, int $status): ResponseInterface
    {
        $renderer = ($this->resolveRenderer)();

        return new HtmlResponse(
            $renderer === null ? $this->plain($title, $message) : $this->rivet($renderer, $title, $message),
            $status,
        );
    }

    private function rivet(Renderer $renderer, string $title, string $message): string
    {
        $renderer->reset();
        $context = $renderer->context();

        $page = new Page(title: $title, heading: $title);
        $context->open($page);

        try {
            return $page->render($context, Html::el('p')->text($message)->render());
        } finally {
            $context->close();
        }
    }

    private function plain(string $title, string $message): string
    {
        $document = Html::el('html')->attr('lang', 'en')->children(
            Html::el('head')->children(
                Html::el('meta')->attr('charset', 'utf-8'),
                Html::el('title')->text($title),
            ),
            Html::el('body')->children(
                Html::el('main')->children(
                    Html::el('h1')->text($title),
                    Html::el('p')->text($message),
                ),
            ),
        );

        return '<!doctype html>' . $document->render();
    }
}

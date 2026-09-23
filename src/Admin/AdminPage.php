<?php

declare(strict_types=1);

namespace Guild\Framework\Admin;

use Closure;
use Guild\Rivet\Component\Breadcrumbs;
use Guild\Rivet\Component\Page\Page;
use Guild\Rivet\Component\Page\PageBreadcrumbs;
use Guild\Rivet\Render\Renderer;
use Laminas\Diactoros\Response\HtmlResponse;

/**
 * Renders a framework administration page as a complete rvt_page document.
 *
 * With addRivet(), the application's own Renderer is used, so the page carries
 * the application's title, assets and navigation, including the System
 * settings menu. Without it, a fallback renderer whose navigation is the
 * administration menu alone.
 *
 * Built from Rivet component objects, never template files: the framework
 * ships none, and Latte cannot load one from outside the application.
 *
 * @internal
 */
final class AdminPage
{
    private ?Renderer $renderer = null;

    /**
     * @param  Closure(): Renderer  $resolveRenderer
     */
    public function __construct(private readonly Closure $resolveRenderer)
    {
    }

    /**
     * @param  string  $body  Pre-rendered markup; callers escape anything they did not build with Rivet.
     * @param  list<array{label: string, href?: string}>  $breadcrumbs
     */
    public function render(string $title, string $body, array $breadcrumbs = []): string
    {
        $renderer = $this->renderer ??= ($this->resolveRenderer)();
        $renderer->reset();
        $context = $renderer->context();

        $page = new Page(title: $title, heading: $title);
        $context->open($page);

        try {
            if ($breadcrumbs !== []) {
                new PageBreadcrumbs()->render($context, new Breadcrumbs($breadcrumbs)->render($context));
            }

            return $page->render($context, $body);
        } finally {
            $context->close();
        }
    }

    /**
     * @param  list<array{label: string, href?: string}>  $breadcrumbs
     */
    public function response(string $title, string $body, array $breadcrumbs = [], int $status = 200): HtmlResponse
    {
        return new HtmlResponse($this->render($title, $body, $breadcrumbs), $status);
    }
}

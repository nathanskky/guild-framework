<?php

declare(strict_types=1);

namespace Guild\Framework\Admin;

use Guild\Rivet\Component\Alert;
use Guild\Rivet\Enum\AlertStyle;
use Guild\Rivet\Html\Html;
use Guild\Rivet\Render\RenderContext;

/**
 * Small markup helpers shared by the administration pages. Everything passed
 * as text is escaped.
 *
 * @internal
 */
final class Markup
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<Html|string>>  $rows  a string cell is escaped text
     */
    public static function table(string $caption, array $headers, array $rows, string $empty): string
    {
        if ($rows === []) {
            return Html::el('p')->text($empty)->render();
        }

        $head = Html::el('tr');

        foreach ($headers as $header) {
            $head->children(Html::el('th')->attr('scope', 'col')->text($header));
        }

        $body = Html::el('tbody');

        foreach ($rows as $row) {
            $tr = Html::el('tr');

            foreach ($row as $cell) {
                $tr->children($cell instanceof Html ? Html::el('td')->children($cell) : Html::el('td')->text($cell));
            }

            $body->children($tr);
        }

        return Html::el('table')
            ->class('rvt-table-stripes')
            ->children(Html::el('caption')->class('rvt-sr-only')->text($caption), Html::el('thead')->children($head), $body)
            ->render();
    }

    public static function link(string $href, string $text): Html
    {
        return Html::el('a')->attr('href', $href)->text($text);
    }

    public static function code(string $text): Html
    {
        return Html::el('code')->text($text);
    }

    public static function flash(?string $message): string
    {
        if ($message === null) {
            return '';
        }

        return new Alert(title: $message, style: AlertStyle::Success)->render(new RenderContext());
    }

    /**
     * @param  list<string>  $lines
     */
    public static function warning(string $title, array $lines): string
    {
        $list = Html::el('ul');

        foreach ($lines as $line) {
            $list->children(Html::el('li')->text($line));
        }

        return new Alert(title: $title, style: AlertStyle::Warning, dismissible: false)
            ->render(new RenderContext(), $list->render());
    }
}

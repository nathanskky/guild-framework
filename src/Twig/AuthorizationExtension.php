<?php

declare(strict_types=1);

namespace Guild\Framework\Twig;

use Guild\Framework\Authorization\TemplateAuthorization;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * can() and cannot() for Twig templates:
 *
 *     {% if can('documents.update', document) %}…{% endif %}
 */
final class AuthorizationExtension extends AbstractExtension
{
    public function __construct(private readonly TemplateAuthorization $authorization)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('can', $this->authorization->can(...)),
            new TwigFunction('cannot', $this->authorization->cannot(...)),
        ];
    }
}

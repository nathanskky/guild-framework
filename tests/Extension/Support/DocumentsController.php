<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Extension\Support;

use Guild\Framework\Authorization\Gate;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * An application controller for tests: it answers only to people who hold
 * TestPermission::DocumentsView.
 */
final readonly class DocumentsController
{
    public function __construct(private Gate $gate)
    {
    }

    public function index(): ResponseInterface
    {
        $this->gate->authorize(TestPermission::DocumentsView);

        return new TextResponse('documents');
    }
}

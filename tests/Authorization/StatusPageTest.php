<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization;

use Guild\Framework\Authorization\StatusPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusPage::class)]
final class StatusPageTest extends TestCase
{
    public function testTheMessageIsEscaped(): void
    {
        $page = new StatusPage(static fn () => null);

        $response = $page->response('<b>Title</b>', '<script>x</script>', 403);

        self::assertStringNotContainsString('<script>', (string) $response->getBody(), 'text is escaped, not interpreted');
        self::assertStringContainsString('&lt;b&gt;Title&lt;/b&gt;', (string) $response->getBody(), 'in the title and heading');
    }
}

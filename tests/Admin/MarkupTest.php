<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Admin;

use Guild\Framework\Admin\Markup;
use Guild\Framework\Admin\PermissionCatalog;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Markup::class)]
#[CoversClass(PermissionCatalog::class)]
final class MarkupTest extends TestCase
{
    public function testTableCellsAreEscapedUnlessBuiltAsMarkup(): void
    {
        $html = Markup::table('Things', ['Name', 'Link'], [['<b>x</b>', Markup::link('/a', 'A & B')]], 'None.');

        self::assertStringContainsString('<td>&lt;b&gt;x&lt;/b&gt;</td>', $html, 'a string cell is text');
        self::assertStringContainsString('<td><a href="/a">A &amp; B</a></td>', $html, 'a built element is kept, its text escaped');
    }

    public function testAnEmptyTableIsASentence(): void
    {
        self::assertSame('<p>None yet.</p>', Markup::table('Things', ['Name'], [], 'None yet.'), 'no empty table shell');
    }

    public function testNoFlashRendersNothing(): void
    {
        self::assertSame('', Markup::flash(null));
        self::assertStringContainsString('Saved.', Markup::flash('Saved.'));
    }

    public function testTheCatalogKnowsTheApplicationsPermissions(): void
    {
        $catalog = new PermissionCatalog(TestPermission::class);

        self::assertTrue($catalog->isKnown('documents.update'), 'a defined value');
        self::assertFalse($catalog->isKnown('documents.retired'), 'an orphan');
        self::assertSame(TestPermission::cases(), $catalog->cases(), 'every case, in declaration order');
    }
}

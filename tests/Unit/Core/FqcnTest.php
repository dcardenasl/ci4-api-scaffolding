<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use dcardenasl\Ci4ApiScaffolding\Core\Fqcn;
use PHPUnit\Framework\TestCase;

final class FqcnTest extends TestCase
{
    public function testShortNameReturnsLastSegment(): void
    {
        $this->assertSame('Foo', Fqcn::shortName('App\\Bar\\Foo'));
        $this->assertSame('Foo', Fqcn::shortName('Foo'));
        $this->assertSame('Foo', Fqcn::shortName('\\App\\Foo'));
    }

    public function testNamespaceReturnsEverythingBeforeLastSegment(): void
    {
        $this->assertSame('App\\Bar', Fqcn::namespace('App\\Bar\\Foo'));
        $this->assertSame('App\\Bar', Fqcn::namespace('\\App\\Bar\\Foo'));
        $this->assertSame('', Fqcn::namespace('Foo'));
    }
}

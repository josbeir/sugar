<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use function Sugar\Core\Runtime\json;
use function Sugar\Core\Runtime\raw;

/**
 * Tests runtime fallback helper functions.
 */
final class FunctionsTest extends TestCase
{
    public function testRawReturnsValueUnchanged(): void
    {
        $value = ['title' => 'Hello'];

        $this->assertSame($value, raw($value));
        $this->assertNull(raw());
    }

    public function testJsonDelegatesToEscaperJsonEncoding(): void
    {
        $value = ['title' => '<script>'];

        $this->assertSame('{"title":"\\u003Cscript\\u003E"}', json($value));
    }
}

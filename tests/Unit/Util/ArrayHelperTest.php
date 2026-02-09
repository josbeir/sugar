<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Sugar\Util\ArrayHelper;

final class ArrayHelperTest extends TestCase
{
    public function testNormalizeStringListReturnsNullForNull(): void
    {
        $this->assertNull(ArrayHelper::normalizeStringList(null));
    }

    public function testNormalizeStringListReturnsNullForEmptyInput(): void
    {
        $this->assertNull(ArrayHelper::normalizeStringList([]));
    }

    public function testNormalizeStringListFiltersNonStrings(): void
    {
        $result = ArrayHelper::normalizeStringList(['alpha', 1, true, null, 'beta']);

        $this->assertSame(['alpha', 'beta'], $result);
    }

    public function testNormalizeStringListTrimsAndRemovesEmptyValues(): void
    {
        $result = ArrayHelper::normalizeStringList(['  alpha  ', ' ', '', "\t", 'beta']);

        $this->assertSame(['alpha', 'beta'], $result);
    }

    public function testNormalizeStringListReturnsSortedUniqueValues(): void
    {
        $result = ArrayHelper::normalizeStringList(['beta', 'alpha', 'beta', 'gamma']);

        $this->assertSame(['alpha', 'beta', 'gamma'], $result);
    }
}

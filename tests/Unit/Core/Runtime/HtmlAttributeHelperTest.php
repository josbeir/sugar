<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Runtime\HtmlAttributeHelper;

final class HtmlAttributeHelperTest extends TestCase
{
    public function testClassNamesIncludesStringableNumericValues(): void
    {
        $value = new class {
            public function __toString(): string
            {
                return 'btn-primary';
            }
        };

        $result = HtmlAttributeHelper::classNames(['btn', $value, 'active' => true, 'hidden' => false]);

        $this->assertSame('btn btn-primary active', $result);
    }

    public function testSpreadAttrsSkipsUnsupportedValuesWithoutCastingArrayToString(): void
    {
        $result = HtmlAttributeHelper::spreadAttrs([
            'id' => 'main',
            'data-obj' => new class {
                public function __toString(): string
                {
                    return 'obj';
                }
            },
            'data-arr' => ['skip'],
            'disabled' => true,
            'hidden' => false,
            'nullable' => null,
        ]);

        $this->assertStringContainsString('id="main"', $result);
        $this->assertStringContainsString('data-obj="obj"', $result);
        $this->assertStringContainsString('disabled', $result);
        $this->assertStringNotContainsString('data-arr', $result);
        $this->assertStringNotContainsString('Array', $result);
        $this->assertStringNotContainsString('hidden', $result);
        $this->assertStringNotContainsString('nullable', $result);
    }

    public function testClassAttributeReturnsEmptyStringWhenClassListIsEmpty(): void
    {
        $result = HtmlAttributeHelper::classAttribute(['class' => false]);

        $this->assertSame('', $result);
    }

    public function testClassAttributeReturnsEscapedAttributeWhenClassListIsNotEmpty(): void
    {
        $result = HtmlAttributeHelper::classAttribute(['btn', 'cta"primary']);

        $this->assertSame('class="btn cta&quot;primary"', $result);
    }

    public function testMergeClassValuesCombinesExistingAndIncomingValues(): void
    {
        $result = HtmlAttributeHelper::mergeClassValues('card', ['active' => true, 'hidden' => false]);

        $this->assertSame('card active', $result);
    }
}

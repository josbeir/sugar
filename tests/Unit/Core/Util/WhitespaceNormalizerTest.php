<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Util;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Util\WhitespaceNormalizer;

final class WhitespaceNormalizerTest extends TestCase
{
    public function testCollapseSequencesCollapsesTabsAndNewlinesToSingleSpaces(): void
    {
        $input = "A\n\t\tB\r\n   C";

        $this->assertSame('A B C', WhitespaceNormalizer::collapseSequences($input));
    }

    public function testCollapseSequencesPreservesLeadingAndTrailingWhitespace(): void
    {
        $input = "  A\nB\t ";

        $this->assertSame(' A B ', WhitespaceNormalizer::collapseSequences($input));
    }

    public function testNormalizeCollapsesAndTrimsString(): void
    {
        $input = "\n\t  Glaze   Documentation\r\n  ";

        $this->assertSame('Glaze Documentation', WhitespaceNormalizer::normalize($input));
    }
}

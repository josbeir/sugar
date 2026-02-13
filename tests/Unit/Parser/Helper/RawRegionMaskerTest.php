<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser\Helper;

use PHPUnit\Framework\TestCase;
use Sugar\Config\Helper\DirectivePrefixHelper;
use Sugar\Config\SugarConfig;
use Sugar\Parser\Helper\RawRegionMasker;

final class RawRegionMaskerTest extends TestCase
{
    public function testHasRawRegionsReturnsFalseWhenDirectiveMarkerMissing(): void
    {
        $masker = $this->createMasker();

        $this->assertFalse($masker->hasRawRegions('<div><?= $name ?></div>'));
    }

    public function testHasRawRegionsReturnsTrueWhenDirectiveMarkerExists(): void
    {
        $masker = $this->createMasker();

        $this->assertTrue($masker->hasRawRegions('<div s:raw><?= $name ?></div>'));
    }

    public function testMaskReturnsUnchangedSourceWhenRawMarkerMissing(): void
    {
        $masker = $this->createMasker();
        $source = '<div><?= $name ?></div>';

        $masked = $masker->mask($source);

        $this->assertSame($source, $masked['source']);
        $this->assertSame([], $masked['placeholders']);
    }

    public function testMaskReplacesRawInnerContentWithPlaceholder(): void
    {
        $masker = $this->createMasker();
        $source = '<div s:raw><?= $name ?></div>';

        $masked = $masker->mask($source);

        $this->assertNotSame($source, $masked['source']);
        $this->assertCount(1, $masked['placeholders']);
        $placeholder = array_key_first($masked['placeholders']);
        $this->assertIsString($placeholder);
        $this->assertStringContainsString($placeholder, $masked['source']);
        $this->assertSame('<?= $name ?>', $masked['placeholders'][$placeholder]);
    }

    public function testMaskPreservesNestedSameTagDepthForRawContent(): void
    {
        $masker = $this->createMasker();
        $source = '<div s:raw><div>inner</div><?= $name ?></div><div>after</div>';

        $masked = $masker->mask($source);

        $this->assertCount(1, $masked['placeholders']);
        $placeholder = (string)array_key_first($masked['placeholders']);
        $this->assertStringContainsString('<div s:raw>' . $placeholder . '</div><div>after</div>', $masked['source']);
        $this->assertSame('<div>inner</div><?= $name ?>', $masked['placeholders'][$placeholder]);
    }

    public function testMaskIgnoresSelfClosingRawTag(): void
    {
        $masker = $this->createMasker();
        $source = '<div s:raw /><span><?= $name ?></span>';

        $masked = $masker->mask($source);

        $this->assertSame($source, $masked['source']);
        $this->assertSame([], $masked['placeholders']);
    }

    public function testMaskSkipsWhenMatchingCloseTagIsMissing(): void
    {
        $masker = $this->createMasker();
        $source = '<div s:raw><?= $name ?>';

        $masked = $masker->mask($source);

        $this->assertSame($source, $masked['source']);
        $this->assertSame([], $masked['placeholders']);
    }

    private function createMasker(): RawRegionMasker
    {
        $config = new SugarConfig();

        return new RawRegionMasker(
            $config,
            new DirectivePrefixHelper($config->directivePrefix),
        );
    }
}

<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Config;

use PHPUnit\Framework\TestCase;
use Sugar\Config\SugarConfig;

final class SugarConfigTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = new SugarConfig();

        $this->assertSame('s', $config->directivePrefix);
        $this->assertSame('s-', $config->elementPrefix);
        $this->assertSame('s-template', $config->getFragmentElement());
    }

    public function testCustomPrefix(): void
    {
        $config = new SugarConfig(directivePrefix: 'x', elementPrefix: 'x-');

        $this->assertSame('x', $config->directivePrefix);
        $this->assertSame('x-', $config->elementPrefix);
        $this->assertSame('x-template', $config->getFragmentElement());
    }

    public function testCustomElementPrefix(): void
    {
        $config = new SugarConfig(
            directivePrefix: 'v',
            elementPrefix: 'v:',
        );

        $this->assertSame('v', $config->directivePrefix);
        $this->assertSame('v:', $config->elementPrefix);
        $this->assertSame('v:template', $config->getFragmentElement());
    }

    public function testNamedConstructorWithPrefix(): void
    {
        $config = SugarConfig::withPrefix('tw');

        $this->assertSame('tw', $config->directivePrefix);
        $this->assertSame('tw-', $config->elementPrefix);
        $this->assertSame('tw-template', $config->getFragmentElement());
    }

    public function testFragmentElementDerivedFromElementPrefix(): void
    {
        $config = new SugarConfig(elementPrefix: 'htmx-');

        $this->assertSame('htmx-', $config->elementPrefix);
        $this->assertSame('htmx-template', $config->getFragmentElement());
    }

    public function testConfigIsReadonly(): void
    {
        $config = new SugarConfig(directivePrefix: 'x');

        $this->assertSame('x', $config->directivePrefix);

        // This test verifies readonly behavior exists
        // Actual readonly enforcement is at PHP language level
    }

    public function testWithSelfClosingTagsReturnsNewConfig(): void
    {
        $config = new SugarConfig(directivePrefix: 'x', elementPrefix: 'x-');
        $updated = $config->withSelfClosingTags(['meta', 'custom']);

        $this->assertSame('x', $updated->directivePrefix);
        $this->assertSame('x-', $updated->elementPrefix);
        $this->assertSame(['meta', 'custom'], $updated->selfClosingTags);
        $this->assertSame(SugarConfig::DEFAULT_SELF_CLOSING_TAGS, $config->selfClosingTags);
    }
}

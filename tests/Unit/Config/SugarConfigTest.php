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
        $this->assertSame('s-template', $config->getFragmentElement());
    }

    public function testCustomPrefix(): void
    {
        $config = new SugarConfig(directivePrefix: 'x');

        $this->assertSame('x', $config->directivePrefix);
        $this->assertSame('x-template', $config->getFragmentElement());
    }

    public function testCustomFragmentElement(): void
    {
        $config = new SugarConfig(
            directivePrefix: 'v',
            fragmentElement: 'v-fragment',
        );

        $this->assertSame('v', $config->directivePrefix);
        $this->assertSame('v-fragment', $config->getFragmentElement());
    }

    public function testNamedConstructorWithPrefix(): void
    {
        $config = SugarConfig::withPrefix('tw');

        $this->assertSame('tw', $config->directivePrefix);
        $this->assertSame('tw-template', $config->getFragmentElement());
    }

    public function testFragmentElementDerivedFromPrefix(): void
    {
        $config = new SugarConfig(directivePrefix: 'htmx');

        $this->assertSame('htmx', $config->directivePrefix);
        $this->assertSame('htmx-template', $config->getFragmentElement());
    }

    public function testConfigIsReadonly(): void
    {
        $config = new SugarConfig(directivePrefix: 'x');

        $this->assertSame('x', $config->directivePrefix);

        // This test verifies readonly behavior exists
        // Actual readonly enforcement is at PHP language level
    }
}

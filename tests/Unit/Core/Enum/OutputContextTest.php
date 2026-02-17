<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Enum;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Escape\Enum\OutputContext;

final class OutputContextTest extends TestCase
{
    public function testEnumCases(): void
    {
        $this->assertSame('html', OutputContext::HTML->value);
        $this->assertSame('html_attr', OutputContext::HTML_ATTRIBUTE->value);
        $this->assertSame('javascript', OutputContext::JAVASCRIPT->value);
        $this->assertSame('json', OutputContext::JSON->value);
        $this->assertSame('json_attr', OutputContext::JSON_ATTRIBUTE->value);
        $this->assertSame('css', OutputContext::CSS->value);
        $this->assertSame('url', OutputContext::URL->value);
        $this->assertSame('raw', OutputContext::RAW->value);
    }

    public function testAllCasesExist(): void
    {
        $cases = OutputContext::cases();

        $this->assertCount(8, $cases);
    }

    public function testTryFrom(): void
    {
        $this->assertSame(OutputContext::HTML, OutputContext::tryFrom('html'));
        $this->assertSame(OutputContext::JAVASCRIPT, OutputContext::tryFrom('javascript'));
        $this->assertNull(OutputContext::tryFrom('invalid'));
    }

    public function testFrom(): void
    {
        $this->assertSame(OutputContext::HTML, OutputContext::from('html'));
        $this->assertSame(OutputContext::RAW, OutputContext::from('raw'));
    }
}

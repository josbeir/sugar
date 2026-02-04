<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Sugar\Enum\State;

final class StateTest extends TestCase
{
    public function testEnumCases(): void
    {
        $this->assertSame('html_content', State::HTML_CONTENT->value);
        $this->assertSame('in_tag', State::IN_TAG->value);
        $this->assertSame('in_attribute', State::IN_ATTRIBUTE->value);
        $this->assertSame('in_attribute_value', State::IN_ATTRIBUTE_VALUE->value);
        $this->assertSame('in_script_tag', State::IN_SCRIPT_TAG->value);
        $this->assertSame('in_style_tag', State::IN_STYLE_TAG->value);
        $this->assertSame('in_string_single', State::IN_STRING_SINGLE->value);
        $this->assertSame('in_string_double', State::IN_STRING_DOUBLE->value);
    }

    public function testAllCasesExist(): void
    {
        $cases = State::cases();

        $this->assertCount(8, $cases);
    }

    public function testTryFrom(): void
    {
        $this->assertSame(State::HTML_CONTENT, State::tryFrom('html_content'));
        $this->assertSame(State::IN_TAG, State::tryFrom('in_tag'));
        $this->assertNull(State::tryFrom('invalid'));
    }
}

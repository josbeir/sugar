<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Config\SugarConfig;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;

final class ParserConfigTest extends TestCase
{
    use CompilerTestTrait;

    public function testCustomFragmentElement(): void
    {
        $config = SugarConfig::withPrefix('x');
        $parser = $this->createParser($config);

        $ast = $parser->parse('<x-template>content</x-template>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(FragmentNode::class, $ast->children[0]);
    }

    public function testCustomFragmentElementNameOverride(): void
    {
        $config = SugarConfig::withPrefix('v');
        $parser = $this->createParser($config);

        // Both v-template and custom component names work
        $ast = $parser->parse('<v-template>content</v-template>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(FragmentNode::class, $ast->children[0]);
    }

    public function testOldFragmentElementIgnoredWithCustomConfig(): void
    {
        $config = SugarConfig::withPrefix('x');
        $parser = $this->createParser($config);

        $ast = $parser->parse('<s-template>content</s-template>');

        // Should parse as regular element, not fragment
        $this->assertCount(1, $ast->children);
        $this->assertNotInstanceOf(FragmentNode::class, $ast->children[0]);
    }

    public function testCustomSelfClosingTags(): void
    {
        $config = new SugarConfig(selfClosingTags: ['custom']);
        $parser = $this->createParser($config);

        $ast = $parser->parse('<custom><div>Content</div>');

        $this->assertCount(2, $ast->children);

        $custom = $ast->children[0];
        $this->assertInstanceOf(ElementNode::class, $custom);
        $this->assertSame('custom', $custom->tag);
        $this->assertTrue($custom->selfClosing);
        $this->assertCount(0, $custom->children);

        $div = $ast->children[1];
        $this->assertInstanceOf(ElementNode::class, $div);
        $this->assertSame('div', $div->tag);
        $this->assertCount(1, $div->children);
        $this->assertInstanceOf(TextNode::class, $div->children[0]);
        $this->assertSame('Content', $div->children[0]->content);
    }
}

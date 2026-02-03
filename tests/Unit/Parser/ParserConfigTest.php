<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\FragmentNode;
use Sugar\Config\SugarConfig;
use Sugar\Parser\Parser;

final class ParserConfigTest extends TestCase
{
    public function testDefaultFragmentElement(): void
    {
        $parser = new Parser();
        $ast = $parser->parse('<s-template>content</s-template>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(FragmentNode::class, $ast->children[0]);
    }

    public function testCustomFragmentElement(): void
    {
        $config = new SugarConfig(directivePrefix: 'x');
        $parser = new Parser($config);

        $ast = $parser->parse('<x-template>content</x-template>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(FragmentNode::class, $ast->children[0]);
    }

    public function testCustomFragmentElementNameOverride(): void
    {
        $config = new SugarConfig(
            directivePrefix: 'v',
            fragmentElement: 'v-fragment',
        );
        $parser = new Parser($config);

        $ast = $parser->parse('<v-fragment>content</v-fragment>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(FragmentNode::class, $ast->children[0]);
    }

    public function testOldFragmentElementIgnoredWithCustomConfig(): void
    {
        $config = new SugarConfig(directivePrefix: 'x');
        $parser = new Parser($config);

        $ast = $parser->parse('<s-template>content</s-template>');

        // Should parse as regular element, not fragment
        $this->assertCount(1, $ast->children);
        $this->assertNotInstanceOf(FragmentNode::class, $ast->children[0]);
    }
}

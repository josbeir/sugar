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
        $config = SugarConfig::withPrefix('x');
        $parser = new Parser($config);

        $ast = $parser->parse('<x-template>content</x-template>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(FragmentNode::class, $ast->children[0]);
    }

    public function testCustomFragmentElementNameOverride(): void
    {
        $config = SugarConfig::withPrefix('v');
        $parser = new Parser($config);

        // Both v-template and custom component names work
        $ast = $parser->parse('<v-template>content</v-template>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(FragmentNode::class, $ast->children[0]);
    }

    public function testOldFragmentElementIgnoredWithCustomConfig(): void
    {
        $config = SugarConfig::withPrefix('x');
        $parser = new Parser($config);

        $ast = $parser->parse('<s-template>content</s-template>');

        // Should parse as regular element, not fragment
        $this->assertCount(1, $ast->children);
        $this->assertNotInstanceOf(FragmentNode::class, $ast->children[0]);
    }
}

<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\FragmentNode;
use Sugar\Config\SugarConfig;
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
}

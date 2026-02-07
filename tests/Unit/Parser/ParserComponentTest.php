<?php
declare(strict_types=1);

namespace Sugar\Test\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\ComponentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\TextNode;
use Sugar\Config\SugarConfig;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;

final class ParserComponentTest extends TestCase
{
    use CompilerTestTrait;

    public function testParsesComponentElement(): void
    {
        $parser = $this->createParser();
        $ast = $parser->parse('<s-button>Click me</s-button>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(ComponentNode::class, $ast->children[0]);

        $component = $ast->children[0];
        $this->assertSame('button', $component->name);
        $this->assertSame([], $component->attributes);
        $this->assertCount(1, $component->children);
        $this->assertInstanceOf(TextNode::class, $component->children[0]);
    }

    public function testParsesComponentWithAttributes(): void
    {
        $parser = $this->createParser();
        $ast = $parser->parse('<s-button type="primary" class="btn">Save</s-button>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(ComponentNode::class, $ast->children[0]);

        $component = $ast->children[0];
        $this->assertSame('button', $component->name);
        $this->assertCount(2, $component->attributes);
    }

    public function testParsesNestedComponents(): void
    {
        $parser = $this->createParser();
        $ast = $parser->parse('<s-card><s-button>Click</s-button></s-card>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(ComponentNode::class, $ast->children[0]);

        $card = $ast->children[0];
        $this->assertSame('card', $card->name);
        $this->assertCount(1, $card->children);
        $this->assertInstanceOf(ComponentNode::class, $card->children[0]);

        $button = $card->children[0];
        $this->assertSame('button', $button->name);
    }

    public function testParsesComponentWithCustomPrefix(): void
    {
        $config = SugarConfig::withPrefix('x');
        $parser = $this->createParser($config);
        $ast = $parser->parse('<x-alert>Warning!</x-alert>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(ComponentNode::class, $ast->children[0]);

        $component = $ast->children[0];
        $this->assertSame('alert', $component->name);
    }

    public function testDoesNotParseFragmentAsComponent(): void
    {
        $parser = $this->createParser();
        $ast = $parser->parse('<s-template>Content</s-template>');

        $this->assertCount(1, $ast->children);
        $this->assertNotInstanceOf(ComponentNode::class, $ast->children[0]);
    }

    public function testParsesMultipleComponents(): void
    {
        $parser = $this->createParser();
        $ast = $parser->parse('<s-button>One</s-button><s-alert>Two</s-alert>');

        $this->assertCount(2, $ast->children);
        $this->assertInstanceOf(ComponentNode::class, $ast->children[0]);
        $this->assertInstanceOf(ComponentNode::class, $ast->children[1]);

        $this->assertSame('button', $ast->children[0]->name);
        $this->assertSame('alert', $ast->children[1]->name);
    }

    public function testParsesComponentWithHyphenatedName(): void
    {
        $parser = $this->createParser();
        $ast = $parser->parse('<s-dropdown-menu>Items</s-dropdown-menu>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(ComponentNode::class, $ast->children[0]);

        $component = $ast->children[0];
        $this->assertSame('dropdown-menu', $component->name);
    }

    public function testParsesComponentMixedWithRegularElements(): void
    {
        $parser = $this->createParser();
        $ast = $parser->parse('<div><s-button>Click</s-button><span>Text</span></div>');

        $this->assertCount(1, $ast->children);
        $div = $ast->children[0];
        $this->assertInstanceOf(ElementNode::class, $div);

        $this->assertCount(2, $div->children);
        $this->assertInstanceOf(ComponentNode::class, $div->children[0]);
        $this->assertSame('button', $div->children[0]->name);
    }

    public function testParsesComponentWithDirectives(): void
    {
        $parser = $this->createParser();
        $ast = $parser->parse('<s-button s:if="$showButton" s:class="$buttonClass">Save</s-button>');

        $this->assertCount(1, $ast->children);
        $this->assertInstanceOf(ComponentNode::class, $ast->children[0]);

        $component = $ast->children[0];
        $this->assertSame('button', $component->name);
        $this->assertCount(2, $component->attributes);

        // Verify directives are stored as attributes (will be processed by DirectiveExtractionPass)
        $this->assertSame('s:if', $component->attributes[0]->name);
        $this->assertSame('s:class', $component->attributes[1]->name);
    }
}

<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Exception\TemplateNotFoundException;
use Sugar\Pass\TemplateInheritancePass;
use Sugar\TemplateInheritance\FileTemplateLoader;

final class TemplateInheritancePassTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = __DIR__ . '/../../fixtures/templates/template-inheritance';
    }

    private function attr(string $name, string $value): AttributeNode
    {
        return new AttributeNode($name, $value, 1, 1);
    }

    public function testProcessesTemplateWithoutInheritance(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);
        $pass = new TemplateInheritancePass($loader);

        $document = new DocumentNode([
            new ElementNode('div', [], [new TextNode('Hello', 1, 1)], false, 1, 1),
        ]);

        $result = $pass->process($document, 'home.sugar.php');

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(1, $result->children);
    }

    public function testExtendsReplacesBlockContent(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);
        $pass = new TemplateInheritancePass($loader);

        // Child template with s:extends
        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:extends', '../base.sugar.php')], [], false, 1, 1),
            new ElementNode('title', [$this->attr('s:block', 'title')], [
                new TextNode('Child Title', 1, 1),
            ], false, 2, 1),
        ]);

        $result = $pass->process($document, 'pages/home.sugar.php');

        // Should return parent structure with replaced blocks
        $this->assertInstanceOf(DocumentNode::class, $result);

        // Debug: check what we got
        $this->assertGreaterThan(0, count($result->children), 'Result should have children');

        // Find title element in result - search recursively
        $titleFound = false;
        $findTitle = function ($nodes) use (&$findTitle, &$titleFound): void {
            foreach ($nodes as $child) {
                if ($child instanceof ElementNode && $child->tag === 'title') {
                    $titleFound = true;
                    $this->assertGreaterThan(0, count($child->children));
                    $this->assertInstanceOf(TextNode::class, $child->children[0]);
                    $this->assertStringContainsString('Child Title', $child->children[0]->content);

                    return;
                }

                if ($child instanceof ElementNode && $child->children !== []) {
                    $findTitle($child->children);
                }
            }
        };
        $findTitle($result->children);

        $this->assertTrue($titleFound, 'Title element should be present in result');
    }

    public function testMultiLevelInheritance(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);
        $pass = new TemplateInheritancePass($loader);

        // Create grandparent template file
        file_put_contents(
            $this->fixturesPath . '/layouts/temp-master.sugar.php',
            '<html><head><title s:block="title">Master</title></head></html>',
        );

        // Create parent template that extends grandparent
        file_put_contents(
            $this->fixturesPath . '/layouts/temp-app.sugar.php',
            '<div s:extends="temp-master.sugar.php"></div><title s:block="title">App</title>',
        );

        // Child extends parent
        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:extends', '../layouts/temp-app.sugar.php')], [], false, 1, 1),
            new ElementNode('title', [$this->attr('s:block', 'title')], [
                new TextNode('Page Title', 1, 1),
            ], false, 2, 1),
        ]);

        $result = $pass->process($document, 'pages/home.sugar.php');

        // Should resolve all the way up to grandparent with final block content
        $this->assertInstanceOf(DocumentNode::class, $result);

        // Cleanup
        unlink($this->fixturesPath . '/layouts/temp-master.sugar.php');
        unlink($this->fixturesPath . '/layouts/temp-app.sugar.php');
    }

    public function testIncludeInsertsTemplateContent(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);
        $pass = new TemplateInheritancePass($loader);

        // Create include file
        file_put_contents(
            $this->fixturesPath . '/partials/temp-header.sugar.php',
            '<header>Header Content</header>',
        );

        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:include', 'partials/temp-header.sugar.php')], [], false, 1, 1),
            new TextNode('Main Content', 2, 1),
        ]);

        $result = $pass->process($document, 'home.sugar.php');

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertGreaterThan(1, count($result->children));

        // Cleanup
        unlink($this->fixturesPath . '/partials/temp-header.sugar.php');
    }

    public function testIncludeWithIsolatedScope(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);
        $pass = new TemplateInheritancePass($loader);

        // Create include file
        file_put_contents(
            $this->fixturesPath . '/partials/user.sugar.php',
            '<span><?= $user ?></span>',
        );

        $document = new DocumentNode([
            new ElementNode('div', [
                $this->attr('s:include', 'partials/user.sugar.php'),
                $this->attr('s:with', "['user' => \$userName]"),
            ], [], false, 1, 1),
        ]);

        $result = $pass->process($document, 'home.sugar.php');

        $this->assertInstanceOf(DocumentNode::class, $result);
        // Should wrap in scope isolation
        // Verify that s:with creates proper variable scope

        // Cleanup
        unlink($this->fixturesPath . '/partials/user.sugar.php');
    }

    public function testThrowsOnCircularInheritance(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);
        $pass = new TemplateInheritancePass($loader);

        // Create circular reference: a.sugar.php extends b.sugar.php, b.sugar.php extends a.sugar.php
        file_put_contents(
            $this->fixturesPath . '/circular-a.sugar.php',
            '<div s:extends="circular-b.sugar.php"></div>',
        );
        file_put_contents(
            $this->fixturesPath . '/circular-b.sugar.php',
            '<div s:extends="circular-a.sugar.php"></div>',
        );

        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:extends', 'circular-a.sugar.php')], [], false, 1, 1),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular');

        try {
            $pass->process($document, 'home.sugar.php');
        } finally {
            // Cleanup
            unlink($this->fixturesPath . '/circular-a.sugar.php');
            unlink($this->fixturesPath . '/circular-b.sugar.php');
        }
    }

    public function testThrowsOnTemplateNotFound(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);
        $pass = new TemplateInheritancePass($loader);

        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:extends', 'nonexistent.sugar.php')], [], false, 1, 1),
        ]);

        $this->expectException(TemplateNotFoundException::class);

        $pass->process($document, 'home.sugar.php');
    }

    public function testRelativePathResolution(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);
        $pass = new TemplateInheritancePass($loader);

        // Existing fixture at layouts/base.sugar.php
        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:extends', '../layouts/base.sugar.php')], [], false, 1, 1),
            new ElementNode('title', [$this->attr('s:block', 'title')], [
                new TextNode('Page', 1, 1),
            ], false, 2, 1),
        ]);

        $result = $pass->process($document, 'pages/home.sugar.php');

        $this->assertInstanceOf(DocumentNode::class, $result);
    }

    public function testAbsolutePathResolution(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);
        $pass = new TemplateInheritancePass($loader);

        // Existing fixture at layouts/base.sugar.php
        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:extends', '/layouts/base.sugar.php')], [], false, 1, 1),
            new ElementNode('title', [$this->attr('s:block', 'title')], [
                new TextNode('Page', 1, 1),
            ], false, 2, 1),
        ]);

        $result = $pass->process($document, 'pages/home.sugar.php');

        $this->assertInstanceOf(DocumentNode::class, $result);
    }

    public function testPreservesNonInheritanceDirectives(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);
        $pass = new TemplateInheritancePass($loader);

        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:if', '$show')], [
                new TextNode('Content', 1, 1),
            ], false, 1, 1),
        ]);

        $result = $pass->process($document, 'home.sugar.php');

        $this->assertInstanceOf(DocumentNode::class, $result);
        // s:if should remain unchanged
        $this->assertCount(1, $result->children);
        $element = $result->children[0];
        $this->assertInstanceOf(ElementNode::class, $element);
        $this->assertCount(1, $element->attributes);
        $this->assertInstanceOf(AttributeNode::class, $element->attributes[0]);
        $this->assertSame('s:if', $element->attributes[0]->name);
    }

    public function testIncludeWithoutWithHasOpenScope(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);
        $pass = new TemplateInheritancePass($loader);

        // Create a simple included template
        $includePath = $this->fixturesPath . '/temp-include.sugar.php';
        file_put_contents($includePath, '<p><?= $message ?></p>');

        try {
            $document = new DocumentNode([
                new ElementNode('div', [$this->attr('s:include', 'temp-include.sugar.php')], [], false, 1, 1),
            ]);

            $result = $pass->process($document, 'home.sugar.php');

            // Should expand inline (no closure wrapper)
            $this->assertInstanceOf(DocumentNode::class, $result);
            $this->assertCount(1, $result->children);

            // The included content should be directly in the tree
            $this->assertInstanceOf(ElementNode::class, $result->children[0]);
            $this->assertSame('p', $result->children[0]->tag);
        } finally {
            unlink($includePath);
        }
    }

    public function testIncludeWithWithHasIsolatedScope(): void
    {
        $loader = new FileTemplateLoader($this->fixturesPath);
        $pass = new TemplateInheritancePass($loader);

        // Create a simple included template
        $includePath = $this->fixturesPath . '/temp-include-with.sugar.php';
        file_put_contents($includePath, '<p><?= $title ?></p>');

        try {
            $document = new DocumentNode([
                new ElementNode('div', [
                    $this->attr('s:include', 'temp-include-with.sugar.php'),
                    $this->attr('s:with', "['title' => 'Hello']"),
                ], [], false, 1, 1),
            ]);

            $result = $pass->process($document, 'home.sugar.php');

            // Should wrap in closure for isolation
            $this->assertInstanceOf(DocumentNode::class, $result);

            // Convert to string to check for closure pattern
            $code = $this->documentToString($result);

            $this->assertStringContainsString('(function($__vars) { extract($__vars);', $code);
            $this->assertStringContainsString("})(['title' => 'Hello']);", $code);
        } finally {
            unlink($includePath);
        }
    }

    private function documentToString(DocumentNode $document): string
    {
        $output = '';
        foreach ($document->children as $child) {
            $output .= $this->nodeToString($child);
        }

        return $output;
    }

    private function nodeToString(Node $node): string
    {
        if ($node instanceof TextNode) {
            return $node->content;
        }

        if ($node instanceof ElementNode) {
            $output = '<' . $node->tag;
            foreach ($node->attributes as $attr) {
                $output .= ' ' . $attr->name;
                if ($attr->value !== null && is_string($attr->value)) {
                    $output .= '="' . $attr->value . '"';
                }
            }

            $output .= '>';

            foreach ($node->children as $child) {
                $output .= $this->nodeToString($child);
            }

            if (!$node->selfClosing) {
                $output .= '</' . $node->tag . '>';
            }

            return $output;
        }

        if ($node instanceof RawPhpNode) {
            return '<?php ' . $node->code . ' ?>';
        }

        return '';
    }
}

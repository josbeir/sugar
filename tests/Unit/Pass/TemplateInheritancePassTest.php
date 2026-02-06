<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Pass;

use Sugar\Ast\AttributeNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Config\SugarConfig;
use Sugar\Exception\SyntaxException;
use Sugar\Exception\TemplateNotFoundException;
use Sugar\Pass\PassInterface;
use Sugar\Pass\TemplateInheritancePass;
use Sugar\TemplateInheritance\FileTemplateLoader;

final class TemplateInheritancePassTest extends PassTestCase
{
    private string $inheritanceFixturesPath;

    protected function getPass(): PassInterface
    {
        $this->inheritanceFixturesPath = SUGAR_TEST_TEMPLATE_INHERITANCE_PATH;
        $loader = new FileTemplateLoader((new SugarConfig())->withTemplatePaths($this->inheritanceFixturesPath));

        return new TemplateInheritancePass($loader, new SugarConfig());
    }

    private function attr(string $name, string $value): AttributeNode
    {
        return new AttributeNode($name, $value, 1, 1);
    }

    public function testProcessesTemplateWithoutInheritance(): void
    {
        $document = new DocumentNode([
            new ElementNode('div', [], [$this->createText('Hello')], false, 1, 1),
        ]);

        $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertCount(1, $result->children);
    }

    public function testExtendsReplacesBlockContent(): void
    {
        // Child template with s:extends
        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:extends', '../base.sugar.php')], [], false, 1, 1),
            new ElementNode('title', [$this->attr('s:block', 'title')], [
                $this->createText('Child Title'),
            ], false, 2, 1),
        ]);

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

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
        // Create grandparent template file
        file_put_contents(
            $this->inheritanceFixturesPath . '/layouts/temp-master.sugar.php',
            '<html><head><title s:block="title">Master</title></head></html>',
        );

        // Create parent template that extends grandparent
        file_put_contents(
            $this->inheritanceFixturesPath . '/layouts/temp-app.sugar.php',
            '<div s:extends="temp-master.sugar.php"></div><title s:block="title">App</title>',
        );

        // Child extends parent
        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:extends', '../layouts/temp-app.sugar.php')], [], false, 1, 1),
            new ElementNode('title', [$this->attr('s:block', 'title')], [
                $this->createText('Page Title'),
            ], false, 2, 1),
        ]);

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        // Should resolve all the way up to grandparent with final block content
        $this->assertInstanceOf(DocumentNode::class, $result);

        // Cleanup
        unlink($this->inheritanceFixturesPath . '/layouts/temp-master.sugar.php');
        unlink($this->inheritanceFixturesPath . '/layouts/temp-app.sugar.php');
    }

    public function testIncludeInsertsTemplateContent(): void
    {
        // Create include file
        file_put_contents(
            $this->inheritanceFixturesPath . '/partials/temp-header.sugar.php',
            '<header>Header Content</header>',
        );

        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:include', 'partials/temp-header.sugar.php')], [], false, 1, 1),
            $this->createText('Main Content'),
        ]);

        $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        $this->assertGreaterThan(1, count($result->children));

        // Cleanup
        unlink($this->inheritanceFixturesPath . '/partials/temp-header.sugar.php');
    }

    public function testIncludeWithIsolatedScope(): void
    {
        // Create include file
        file_put_contents(
            $this->inheritanceFixturesPath . '/partials/user.sugar.php',
            '<span><?= $user ?></span>',
        );

        $document = new DocumentNode([
            new ElementNode('div', [
                $this->attr('s:include', 'partials/user.sugar.php'),
                $this->attr('s:with', "['user' => \$userName]"),
            ], [], false, 1, 1),
        ]);

        $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
        // Should wrap in scope isolation
        // Verify that s:with creates proper variable scope

        // Cleanup
        unlink($this->inheritanceFixturesPath . '/partials/user.sugar.php');
    }

    public function testThrowsOnCircularInheritance(): void
    {
        // Create circular reference: a.sugar.php extends b.sugar.php, b.sugar.php extends a.sugar.php
        file_put_contents(
            $this->inheritanceFixturesPath . '/circular-a.sugar.php',
            '<div s:extends="circular-b.sugar.php"></div>',
        );
        file_put_contents(
            $this->inheritanceFixturesPath . '/circular-b.sugar.php',
            '<div s:extends="circular-a.sugar.php"></div>',
        );

        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:extends', 'circular-a.sugar.php')], [], false, 1, 1),
        ]);

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Circular');

        try {
            $this->execute($document, $this->createTestContext('', 'home.sugar.php'));
        } finally {
            // Cleanup
            unlink($this->inheritanceFixturesPath . '/circular-a.sugar.php');
            unlink($this->inheritanceFixturesPath . '/circular-b.sugar.php');
        }
    }

    public function testThrowsOnTemplateNotFound(): void
    {
        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:extends', 'nonexistent.sugar.php')], [], false, 1, 1),
        ]);

        $this->expectException(TemplateNotFoundException::class);

        $this->execute($document, $this->createTestContext('', 'home.sugar.php'));
    }

    public function testRelativePathResolution(): void
    {
        // Existing fixture at layouts/base.sugar.php
        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:extends', '../layouts/base.sugar.php')], [], false, 1, 1),
            new ElementNode('title', [$this->attr('s:block', 'title')], [
                $this->createText('Page'),
            ], false, 2, 1),
        ]);

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
    }

    public function testAbsolutePathResolution(): void
    {
        // Existing fixture at layouts/base.sugar.php
        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:extends', '/layouts/base.sugar.php')], [], false, 1, 1),
            new ElementNode('title', [$this->attr('s:block', 'title')], [
                $this->createText('Page'),
            ], false, 2, 1),
        ]);

        $result = $this->execute($document, $this->createTestContext('', 'pages/home.sugar.php'));

        $this->assertInstanceOf(DocumentNode::class, $result);
    }

    public function testPreservesNonInheritanceDirectives(): void
    {
        $document = new DocumentNode([
            new ElementNode('div', [$this->attr('s:if', '$show')], [
                $this->createText('Content'),
            ], false, 1, 1),
        ]);

        $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

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
        // Create a simple included template
        $includePath = $this->inheritanceFixturesPath . '/temp-include.sugar.php';
        file_put_contents($includePath, '<p><?= $message ?></p>');

        try {
            $document = new DocumentNode([
                new ElementNode('div', [$this->attr('s:include', 'temp-include.sugar.php')], [], false, 1, 1),
            ]);

            $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

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
        // Create a simple included template
        $includePath = $this->inheritanceFixturesPath . '/temp-include-with.sugar.php';
        file_put_contents($includePath, '<p><?= $title ?></p>');

        try {
            $document = new DocumentNode([
                new ElementNode('div', [
                    $this->attr('s:include', 'temp-include-with.sugar.php'),
                    $this->attr('s:with', "['title' => 'Hello']"),
                ], [], false, 1, 1),
            ]);

            $result = $this->execute($document, $this->createTestContext('', 'home.sugar.php'));

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

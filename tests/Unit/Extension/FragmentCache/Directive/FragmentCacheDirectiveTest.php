<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\FragmentCache\Directive;

use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Extension\FragmentCache\Directive\FragmentCacheDirective;
use Sugar\Tests\Unit\Core\Directive\DirectiveTestCase;

final class FragmentCacheDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        return new FragmentCacheDirective(defaultTtl: 300);
    }

    protected function getDirectiveName(): string
    {
        return 'cache';
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Register cache directive so $this->compiler resolves it during element syntax tests.
        $this->registry->register('cache', $this->directiveCompiler);
    }

    public function testCompileWrapsChildrenWithRuntimeCacheCalls(): void
    {
        $node = $this->directive('cache')
            ->expression("'users-list'")
            ->withChild($this->text('Cached content', 1, 10))
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertCount(3, $result);
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertStringContainsString('FragmentCacheHelper::resolveKey', $result[0]->code);
        $this->assertStringContainsString('FragmentCacheHelper::get', $result[0]->code);
        $this->assertStringContainsString('ob_start(); try {', $result[0]->code);
        $this->assertStringContainsString("'users-list'", $result[0]->code);
        $this->assertStringContainsString('finally {', $result[2]->code);
        $this->assertStringContainsString('FragmentCacheHelper::set', $result[2]->code);
    }

    public function testCompileTreatsBareDirectiveAsAutoKeyMode(): void
    {
        $node = $this->directive('cache')
            ->expression('true')
            ->withChild($this->text('Cached content', 1, 10))
            ->at(1, 1)
            ->build();

        $result = $this->directiveCompiler->compile($node, $this->createTestContext());

        $this->assertCount(3, $result);
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertStringContainsString('= null;', $result[0]->code);
    }

    public function testGetType(): void
    {
        $this->assertSame(DirectiveType::CONTROL_FLOW, $this->directiveCompiler->getType());
    }

    public function testCompileWithoutConfiguredCacheStillWrapsChildren(): void
    {
        $directive = new FragmentCacheDirective();

        $node = $this->directive('cache')
            ->expression("'users-list'")
            ->withChild($this->text('Content', 1, 10))
            ->at(1, 1)
            ->build();

        $result = $directive->compile($node, $this->createTestContext());

        $this->assertCount(3, $result);
        $this->assertInstanceOf(RawPhpNode::class, $result[0]);
        $this->assertInstanceOf(RawPhpNode::class, $result[2]);
        $this->assertStringContainsString('FragmentCacheHelper::get', $result[0]->code);
        $this->assertStringContainsString('FragmentCacheHelper::set', $result[2]->code);
    }

    public function testGetElementExpressionAttribute(): void
    {
        $directive = new FragmentCacheDirective();
        $this->assertSame('key', $directive->getElementExpressionAttribute());
    }

    public function testElementSyntaxCompilesToCacheWrap(): void
    {
        $compiled = $this->compiler->compile("<s-cache key=\"'sidebar'\"><p>cached</p></s-cache>");

        $this->assertContainsPhp('FragmentCacheHelper::resolveKey', $compiled);
        $this->assertContainsPhp("'sidebar'", $compiled);
        $this->assertContainsPhp('ob_start(); try {', $compiled);
        $this->assertContainsPhp('<p>cached</p>', $compiled);
    }

    public function testElementSyntaxWithoutKeyUsesAutoKey(): void
    {
        $compiled = $this->compiler->compile('<s-cache><p>auto</p></s-cache>');

        $this->assertContainsPhp('FragmentCacheHelper::resolveKey', $compiled);
        $this->assertContainsPhp('= null;', $compiled);
        $this->assertContainsPhp('<p>auto</p>', $compiled);
    }
}

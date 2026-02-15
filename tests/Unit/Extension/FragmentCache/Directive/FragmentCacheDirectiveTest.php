<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\FragmentCache\Directive;

use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Enum\DirectiveType;
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
}

<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Directive;

use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\Directive\FragmentCacheDirective;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Enum\DirectiveType;
use Sugar\Tests\Helper\Stub\ArraySimpleCache;

final class FragmentCacheDirectiveTest extends DirectiveTestCase
{
    protected function getDirectiveCompiler(): DirectiveInterface
    {
        $fragmentCache = new ArraySimpleCache();

        return new FragmentCacheDirective(fragmentCache: $fragmentCache, defaultTtl: 300);
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
        $this->assertStringContainsString("'users-list'", $result[0]->code);
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

    public function testCompileWithoutConfiguredCacheReturnsChildrenUnchanged(): void
    {
        $directive = new FragmentCacheDirective();

        $node = $this->directive('cache')
            ->expression("'users-list'")
            ->withChild($this->text('Content', 1, 10))
            ->at(1, 1)
            ->build();

        $result = $directive->compile($node, $this->createTestContext());

        $this->assertCount(1, $result);
        $this->assertInstanceOf(TextNode::class, $result[0]);
        $this->assertSame('Content', $result[0]->content);
    }
}

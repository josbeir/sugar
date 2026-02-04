<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Sugar\Ast\Node;
use Sugar\Enum\DirectiveType;
use Sugar\Exception\UnknownDirectiveException;
use Sugar\Extension\DirectiveCompilerInterface;
use Sugar\Extension\ExtensionRegistry;

final class ExtensionRegistryTest extends TestCase
{
    private ExtensionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ExtensionRegistry();
    }

    public function testRegisterDirective(): void
    {
        $compiler = $this->createMockDirectiveCompiler();

        $this->registry->registerDirective('custom', $compiler);

        $this->assertTrue($this->registry->hasDirective('custom'));
        $this->assertSame($compiler, $this->registry->getDirective('custom'));
    }

    public function testHasDirectiveReturnsFalseForUnregistered(): void
    {
        $this->assertFalse($this->registry->hasDirective('nonexistent'));
    }

    public function testGetDirectiveThrowsForUnregistered(): void
    {
        $this->expectException(UnknownDirectiveException::class);
        $this->expectExceptionMessage('Unknown directive "nonexistent"');

        $this->registry->getDirective('nonexistent');
    }

    public function testAllDirectivesReturnsEmptyArrayInitially(): void
    {
        $this->assertSame([], $this->registry->allDirectives());
    }

    public function testAllDirectivesReturnsRegisteredDirectives(): void
    {
        $compiler1 = $this->createMockDirectiveCompiler();
        $compiler2 = $this->createMockDirectiveCompiler();

        $this->registry->registerDirective('if', $compiler1);
        $this->registry->registerDirective('foreach', $compiler2);

        $all = $this->registry->allDirectives();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('if', $all);
        $this->assertArrayHasKey('foreach', $all);
        $this->assertSame($compiler1, $all['if']);
        $this->assertSame($compiler2, $all['foreach']);
    }

    public function testOverrideDirective(): void
    {
        $compiler1 = $this->createMockDirectiveCompiler();
        $compiler2 = $this->createMockDirectiveCompiler();

        $this->registry->registerDirective('if', $compiler1);
        $this->registry->registerDirective('if', $compiler2);

        $this->assertSame($compiler2, $this->registry->getDirective('if'));
    }

    public function testRegisterDirectiveByClassName(): void
    {
        $this->registry->registerDirective('test', TestDirectiveCompiler::class);

        $this->assertTrue($this->registry->hasDirective('test'));

        $compiler = $this->registry->getDirective('test');
        $this->assertInstanceOf(TestDirectiveCompiler::class, $compiler);
    }

    public function testLazyInstantiationCachesInstance(): void
    {
        $this->registry->registerDirective('test', TestDirectiveCompiler::class);

        $compiler1 = $this->registry->getDirective('test');
        $compiler2 = $this->registry->getDirective('test');

        // Should be the same instance (cached)
        $this->assertSame($compiler1, $compiler2);
    }

    public function testGetDirectiveThrowsForNonExistentClass(): void
    {
        /** @phpstan-ignore argument.type */
        $this->registry->registerDirective('invalid', 'NonExistentClass');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Extension class "NonExistentClass" does not exist');

        $this->registry->getDirective('invalid');
    }

    public function testGetDirectiveThrowsForInvalidInterface(): void
    {
        // Register a class that doesn't implement DirectiveCompilerInterface
        /** @phpstan-ignore argument.type */
        $this->registry->registerDirective('invalid', stdClass::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Extension class "stdClass" must implement');

        $this->registry->getDirective('invalid');
    }

    public function testGetDirectivesByType(): void
    {
        $controlFlow = $this->createMockDirectiveCompiler(DirectiveType::CONTROL_FLOW);
        $attribute = $this->createMockDirectiveCompiler(DirectiveType::ATTRIBUTE);

        $this->registry->registerDirective('if', $controlFlow);
        $this->registry->registerDirective('class', $attribute);
        $this->registry->registerDirective('foreach', $controlFlow);

        $controlFlowDirectives = $this->registry->getDirectivesByType(DirectiveType::CONTROL_FLOW);

        $this->assertCount(2, $controlFlowDirectives);
        $this->assertArrayHasKey('if', $controlFlowDirectives);
        $this->assertArrayHasKey('foreach', $controlFlowDirectives);
        $this->assertArrayNotHasKey('class', $controlFlowDirectives);
    }

    public function testGetDirectivesByTypeWithLazyLoading(): void
    {
        $this->registry->registerDirective('test', TestDirectiveCompiler::class);
        $this->registry->registerDirective('test2', TestDirectiveCompiler::class);

        $controlFlowDirectives = $this->registry->getDirectivesByType(DirectiveType::CONTROL_FLOW);

        $this->assertCount(2, $controlFlowDirectives);
        $this->assertArrayHasKey('test', $controlFlowDirectives);
        $this->assertArrayHasKey('test2', $controlFlowDirectives);
    }

    public function testAllDirectivesWithLazyLoading(): void
    {
        $compiler1 = $this->createMockDirectiveCompiler();
        $this->registry->registerDirective('eager', $compiler1);
        $this->registry->registerDirective('lazy', TestDirectiveCompiler::class);

        $all = $this->registry->allDirectives();

        $this->assertCount(2, $all);
        $this->assertSame($compiler1, $all['eager']);
        $this->assertInstanceOf(TestDirectiveCompiler::class, $all['lazy']);
    }

    private function createMockDirectiveCompiler(?DirectiveType $type = null): DirectiveCompilerInterface
    {
        $type ??= DirectiveType::CONTROL_FLOW;

        return new class ($type) implements DirectiveCompilerInterface {
            public function __construct(private DirectiveType $type)
            {
            }

            /**
             * @param \Sugar\Ast\DirectiveNode $node
             * @return array<\Sugar\Ast\Node>
             */
            public function compile(Node $node): array
            {
                return [];
            }

            public function getType(): DirectiveType
            {
                return $this->type;
            }
        };
    }
}

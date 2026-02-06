<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Sugar\Ast\Node;
use Sugar\Context\CompilationContext;
use Sugar\Enum\DirectiveType;
use Sugar\Exception\UnknownDirectiveException;
use Sugar\Extension\DirectiveCompilerInterface;
use Sugar\Extension\DirectiveRegistry;

final class DirectiveRegistryTest extends TestCase
{
    private DirectiveRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new DirectiveRegistry();
    }

    public function testRegisterDirective(): void
    {
        $compiler = $this->createMockDirectiveCompiler();

        $this->registry->register('custom', $compiler);

        $this->assertTrue($this->registry->has('custom'));
        $this->assertSame($compiler, $this->registry->get('custom'));
    }

    public function testHasDirectiveReturnsFalseForUnregistered(): void
    {
        $this->assertFalse($this->registry->has('nonexistent'));
    }

    public function testGetDirectiveThrowsForUnregistered(): void
    {
        $this->expectException(UnknownDirectiveException::class);
        $this->expectExceptionMessage('Unknown directive "nonexistent"');

        $this->registry->get('nonexistent');
    }

    public function testAllDirectivesReturnsEmptyArrayInitially(): void
    {
        $this->assertSame([], $this->registry->all());
    }

    public function testAllDirectivesReturnsRegisteredDirectives(): void
    {
        $compiler1 = $this->createMockDirectiveCompiler();
        $compiler2 = $this->createMockDirectiveCompiler();

        $this->registry->register('if', $compiler1);
        $this->registry->register('foreach', $compiler2);

        $all = $this->registry->all();

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

        $this->registry->register('if', $compiler1);
        $this->registry->register('if', $compiler2);

        $this->assertSame($compiler2, $this->registry->get('if'));
    }

    public function testRegisterDirectiveByClassName(): void
    {
        $this->registry->register('test', TestDirectiveCompiler::class);

        $this->assertTrue($this->registry->has('test'));

        $compiler = $this->registry->get('test');
        $this->assertInstanceOf(TestDirectiveCompiler::class, $compiler);
    }

    public function testLazyInstantiationCachesInstance(): void
    {
        $this->registry->register('test', TestDirectiveCompiler::class);

        $compiler1 = $this->registry->get('test');
        $compiler2 = $this->registry->get('test');

        // Should be the same instance (cached)
        $this->assertSame($compiler1, $compiler2);
    }

    public function testGetDirectiveThrowsForNonExistentClass(): void
    {
        /** @phpstan-ignore argument.type */
        $this->registry->register('invalid', 'NonExistentClass');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Extension class "NonExistentClass" does not exist');

        $this->registry->get('invalid');
    }

    public function testGetDirectiveThrowsForInvalidInterface(): void
    {
        // Register a class that doesn't implement DirectiveCompilerInterface
        /** @phpstan-ignore argument.type */
        $this->registry->register('invalid', stdClass::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Extension class "stdClass" must implement');

        $this->registry->get('invalid');
    }

    public function testGetDirectivesByType(): void
    {
        $controlFlow = $this->createMockDirectiveCompiler(DirectiveType::CONTROL_FLOW);
        $attribute = $this->createMockDirectiveCompiler(DirectiveType::ATTRIBUTE);

        $this->registry->register('if', $controlFlow);
        $this->registry->register('class', $attribute);
        $this->registry->register('foreach', $controlFlow);

        $controlFlowDirectives = $this->registry->getByType(DirectiveType::CONTROL_FLOW);

        $this->assertCount(2, $controlFlowDirectives);
        $this->assertArrayHasKey('if', $controlFlowDirectives);
        $this->assertArrayHasKey('foreach', $controlFlowDirectives);
        $this->assertArrayNotHasKey('class', $controlFlowDirectives);
    }

    public function testGetDirectivesByTypeWithLazyLoading(): void
    {
        $this->registry->register('test', TestDirectiveCompiler::class);
        $this->registry->register('test2', TestDirectiveCompiler::class);

        $controlFlowDirectives = $this->registry->getByType(DirectiveType::CONTROL_FLOW);

        $this->assertCount(2, $controlFlowDirectives);
        $this->assertArrayHasKey('test', $controlFlowDirectives);
        $this->assertArrayHasKey('test2', $controlFlowDirectives);
    }

    public function testAllDirectivesWithLazyLoading(): void
    {
        $compiler1 = $this->createMockDirectiveCompiler();
        $this->registry->register('eager', $compiler1);
        $this->registry->register('lazy', TestDirectiveCompiler::class);

        $all = $this->registry->all();

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
            public function compile(Node $node, CompilationContext $context): array
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

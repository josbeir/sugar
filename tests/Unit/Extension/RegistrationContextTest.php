<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\Node;
use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Compiler\Pipeline\NodeAction;
use Sugar\Compiler\Pipeline\PipelineContext;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Extension\RegistrationContext;

/**
 * Tests for RegistrationContext
 */
final class RegistrationContextTest extends TestCase
{
    private RegistrationContext $context;

    protected function setUp(): void
    {
        $this->context = new RegistrationContext();
    }

    public function testDirectiveRegistration(): void
    {
        $directive = $this->createStub(DirectiveInterface::class);

        $this->context->directive('custom', $directive);

        $directives = $this->context->getDirectives();
        $this->assertCount(1, $directives);
        $this->assertSame($directive, $directives['custom']);
    }

    public function testDirectiveRegistrationWithClassName(): void
    {
        $this->context->directive('test', TestDirective::class);

        $directives = $this->context->getDirectives();
        $this->assertCount(1, $directives);
        $this->assertSame(TestDirective::class, $directives['test']);
    }

    public function testMultipleDirectiveRegistrations(): void
    {
        $directive1 = $this->createStub(DirectiveInterface::class);
        $directive2 = $this->createStub(DirectiveInterface::class);

        $this->context->directive('one', $directive1);
        $this->context->directive('two', $directive2);

        $directives = $this->context->getDirectives();
        $this->assertCount(2, $directives);
        $this->assertArrayHasKey('one', $directives);
        $this->assertArrayHasKey('two', $directives);
    }

    public function testDirectiveOverwrite(): void
    {
        $directive1 = $this->createStub(DirectiveInterface::class);
        $directive2 = $this->createStub(DirectiveInterface::class);

        $this->context->directive('name', $directive1);
        $this->context->directive('name', $directive2);

        $directives = $this->context->getDirectives();
        $this->assertCount(1, $directives);
        $this->assertSame($directive2, $directives['name']);
    }

    public function testCompilerPassRegistration(): void
    {
        $pass = $this->createStubPass();

        $this->context->compilerPass($pass, 35);

        $passes = $this->context->getPasses();
        $this->assertCount(1, $passes);
        $this->assertSame($pass, $passes[0]['pass']);
        $this->assertSame(35, $passes[0]['priority']);
    }

    public function testCompilerPassDefaultsToZeroPriority(): void
    {
        $pass = $this->createStubPass();

        $this->context->compilerPass($pass);

        $passes = $this->context->getPasses();
        $this->assertCount(1, $passes);
        $this->assertSame(0, $passes[0]['priority']);
    }

    public function testMultipleCompilerPassRegistrations(): void
    {
        $pass1 = $this->createStubPass();
        $pass2 = $this->createStubPass();

        $this->context->compilerPass($pass1, 5);
        $this->context->compilerPass($pass2, 45);

        $passes = $this->context->getPasses();
        $this->assertCount(2, $passes);
        $this->assertSame(5, $passes[0]['priority']);
        $this->assertSame(45, $passes[1]['priority']);
    }

    public function testSamePriorityMultiplePasses(): void
    {
        $pass1 = $this->createStubPass();
        $pass2 = $this->createStubPass();

        $this->context->compilerPass($pass1, 35);
        $this->context->compilerPass($pass2, 35);

        $passes = $this->context->getPasses();
        $this->assertCount(2, $passes);
        $this->assertSame($pass1, $passes[0]['pass']);
        $this->assertSame($pass2, $passes[1]['pass']);
    }

    public function testEmptyByDefault(): void
    {
        $this->assertSame([], $this->context->getDirectives());
        $this->assertSame([], $this->context->getPasses());
    }

    public function testMixedRegistrations(): void
    {
        $directive = $this->createStub(DirectiveInterface::class);
        $pass = $this->createStubPass();

        $this->context->directive('my-dir', $directive);
        $this->context->compilerPass($pass, 45);

        $this->assertCount(1, $this->context->getDirectives());
        $this->assertCount(1, $this->context->getPasses());
    }

    protected function createStubPass(): AstPassInterface
    {
        return new class implements AstPassInterface {
            public function before(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }

            public function after(Node $node, PipelineContext $context): NodeAction
            {
                return NodeAction::none();
            }
        };
    }
}

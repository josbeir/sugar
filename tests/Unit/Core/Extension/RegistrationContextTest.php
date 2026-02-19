<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Extension;

use PHPUnit\Framework\TestCase;
use stdClass;
use Sugar\Core\Ast\Node;
use Sugar\Core\Cache\TemplateCacheInterface;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\Enum\PassPriority;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Extension\DirectiveRegistry;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Core\Loader\StringTemplateLoader;
use Sugar\Core\Loader\TemplateLoaderInterface;
use Sugar\Core\Parser\Parser;

/**
 * Tests for RegistrationContext
 */
final class RegistrationContextTest extends TestCase
{
    private RegistrationContext $context;

    protected function setUp(): void
    {
        $config = new SugarConfig();
        $this->context = new RegistrationContext(
            config: $config,
            templateLoader: new StringTemplateLoader(templates: []),
            templateCache: $this->createStub(TemplateCacheInterface::class),
            parser: new Parser($config),
            directiveRegistry: new DirectiveRegistry(),
        );
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

        $this->context->compilerPass($pass, PassPriority::POST_DIRECTIVE_COMPILATION);

        $passes = $this->context->getPasses();
        $this->assertCount(1, $passes);
        $this->assertSame($pass, $passes[0]['pass']);
        $this->assertSame(PassPriority::POST_DIRECTIVE_COMPILATION, $passes[0]['priority']);
    }

    public function testCompilerPassDefaultsToPostDirectiveCompilationPriority(): void
    {
        $pass = $this->createStubPass();

        $this->context->compilerPass($pass);

        $passes = $this->context->getPasses();
        $this->assertCount(1, $passes);
        $this->assertSame(PassPriority::POST_DIRECTIVE_COMPILATION, $passes[0]['priority']);
    }

    public function testMultipleCompilerPassRegistrations(): void
    {
        $pass1 = $this->createStubPass();
        $pass2 = $this->createStubPass();

        $this->context->compilerPass($pass1, PassPriority::PRE_DIRECTIVE_EXTRACTION);
        $this->context->compilerPass($pass2, PassPriority::CONTEXT_ANALYSIS);

        $passes = $this->context->getPasses();
        $this->assertCount(2, $passes);
        $this->assertSame(PassPriority::PRE_DIRECTIVE_EXTRACTION, $passes[0]['priority']);
        $this->assertSame(PassPriority::CONTEXT_ANALYSIS, $passes[1]['priority']);
    }

    public function testSamePriorityMultiplePasses(): void
    {
        $pass1 = $this->createStubPass();
        $pass2 = $this->createStubPass();

        $this->context->compilerPass($pass1, PassPriority::POST_DIRECTIVE_COMPILATION);
        $this->context->compilerPass($pass2, PassPriority::POST_DIRECTIVE_COMPILATION);

        $passes = $this->context->getPasses();
        $this->assertCount(2, $passes);
        $this->assertSame($pass1, $passes[0]['pass']);
        $this->assertSame($pass2, $passes[1]['pass']);
    }

    public function testEmptyByDefault(): void
    {
        $this->assertSame([], $this->context->getDirectives());
        $this->assertSame([], $this->context->getPasses());
        $this->assertSame([], $this->context->getRuntimeServices());
    }

    public function testMixedRegistrations(): void
    {
        $directive = $this->createStub(DirectiveInterface::class);
        $pass = $this->createStubPass();
        $service = (object)['enabled' => true];

        $this->context->directive('my-dir', $directive);
        $this->context->compilerPass($pass, PassPriority::CONTEXT_ANALYSIS);
        $this->context->runtimeService('custom.service', $service);

        $this->assertCount(1, $this->context->getDirectives());
        $this->assertCount(1, $this->context->getPasses());
        $this->assertSame($service, $this->context->getRuntimeServices()['custom.service']);
    }

    public function testRuntimeServiceRegistrationOverwritesById(): void
    {
        $first = new stdClass();
        $second = new stdClass();

        $this->context->runtimeService('custom.service', $first);
        $this->context->runtimeService('custom.service', $second);

        $services = $this->context->getRuntimeServices();
        $this->assertCount(1, $services);
        $this->assertSame($second, $services['custom.service']);
    }

    public function testProtectedRuntimeServiceMarksServiceIdAsProtected(): void
    {
        $service = new stdClass();

        $this->context->protectedRuntimeService('secure.service', $service);

        $this->assertSame($service, $this->context->getRuntimeServices()['secure.service']);
        $this->assertSame(['secure.service' => true], $this->context->getProtectedServiceIds());
    }

    public function testContextGettersReturnConstructorDependencies(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(templates: []);
        $cache = $this->createStub(TemplateCacheInterface::class);
        $parser = new Parser($config);
        $registry = new DirectiveRegistry();

        $context = new RegistrationContext(
            config: $config,
            templateLoader: $loader,
            templateCache: $cache,
            parser: $parser,
            directiveRegistry: $registry,
        );

        $this->assertSame($config, $context->getConfig());
        $this->assertSame($loader, $context->getTemplateLoader());
        $this->assertSame($cache, $context->getTemplateCache());
        $this->assertSame($parser, $context->getParser());
        $this->assertSame($registry, $context->getDirectiveRegistry());
        $this->assertNull($context->getTemplateContext());
        $this->assertFalse($context->isDebug());
    }

    public function testContextGettersExposeTemplateContextAndDebugFlag(): void
    {
        $config = new SugarConfig();
        $loader = new StringTemplateLoader(templates: []);
        $cache = $this->createStub(TemplateCacheInterface::class);
        $parser = new Parser($config);
        $registry = new DirectiveRegistry();
        $templateContext = (object)['name' => 'ctx'];

        $context = new RegistrationContext(
            config: $config,
            templateLoader: $loader,
            templateCache: $cache,
            parser: $parser,
            directiveRegistry: $registry,
            templateContext: $templateContext,
            debug: true,
        );

        $this->assertInstanceOf(TemplateLoaderInterface::class, $context->getTemplateLoader());
        $this->assertSame($templateContext, $context->getTemplateContext());
        $this->assertTrue($context->isDebug());
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

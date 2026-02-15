<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Compiler;

use PhpParser\Error;
use PhpParser\ErrorHandler;
use PhpParser\Parser as PhpAstParser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\DocumentNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\FragmentNode;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Compiler\PhpSyntaxValidator;
use Sugar\Core\Enum\OutputContext;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Core\Parser\Parser;
use Throwable;

/**
 * Tests for PHP syntax validation helper behavior.
 */
final class PhpSyntaxValidatorTest extends TestCase
{
    public function testGeneratedThrowsSyntaxExceptionWhenGeneratedCodeIsInvalid(): void
    {
        if (!$this->hasPhpParserSupport()) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: '',
            debug: true,
        );

        $validator = new PhpSyntaxValidator();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Generated PHP validation failed');

        $validator->generated("<?php\nif (\n", $context);
    }

    public function testTemplateSegmentsThrowSyntaxExceptionForInvalidOutputExpression(): void
    {
        if (!$this->hasPhpParserSupport()) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $source = '<div><?= $value + ?></div>';
        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: $source,
            debug: true,
        );
        $document = (new Parser())->parse($source);
        $context->stampTemplatePath($document);

        $validator = new PhpSyntaxValidator();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Invalid PHP expression');
        $this->expectExceptionMessage('inline-template');
        $this->expectExceptionMessage('line:1');

        $validator->templateSegments($document, $context);
    }

    public function testTemplateSegmentsThrowSyntaxExceptionForInvalidAttributeExpression(): void
    {
        if (!$this->hasPhpParserSupport()) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $source = '<div title="Hello <?= $value + ?>"></div>';
        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: $source,
            debug: true,
        );
        $document = (new Parser())->parse($source);
        $context->stampTemplatePath($document);

        $validator = new PhpSyntaxValidator();

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Invalid PHP expression');

        $validator->templateSegments($document, $context);
    }

    public function testGeneratedReturnsEarlyWhenDebugDisabled(): void
    {
        $this->expectNotToPerformAssertions();

        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: '',
            debug: false,
        );

        $validator = new PhpSyntaxValidator();
        $validator->generated("<?php\nif (\n", $context);
    }

    public function testTemplateSegmentsReturnEarlyWhenDebugDisabled(): void
    {
        $this->expectNotToPerformAssertions();

        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: '<?= $value ?>',
            debug: false,
        );
        $document = new DocumentNode([
            new OutputNode('$value', true, OutputContext::HTML, 1, 1),
        ]);

        $validator = new PhpSyntaxValidator();
        $validator->templateSegments($document, $context);
    }

    public function testGeneratedReturnsEarlyWhenParserIsUnavailable(): void
    {
        $this->expectNotToPerformAssertions();

        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: '',
            debug: true,
        );

        $validator = new PhpSyntaxValidator();
        $this->forceCachedParser($validator, null, true);

        $validator->generated("<?php\nif (\n", $context);
    }

    public function testTemplateSegmentsReturnsEarlyWhenParserIsUnavailable(): void
    {
        $this->expectNotToPerformAssertions();

        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: '<?= $value ?>',
            debug: true,
        );
        $document = new DocumentNode([
            new OutputNode('$value', true, OutputContext::HTML, 1, 1),
        ]);

        $validator = new PhpSyntaxValidator();
        $this->forceCachedParser($validator, null, true);

        $validator->templateSegments($document, $context);
    }

    public function testGeneratedRethrowsNonPhpParserErrors(): void
    {
        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: '',
            debug: true,
        );

        $validator = new PhpSyntaxValidator();
        $throwingParser = new class (new RuntimeException('generated-failure')) implements PhpAstParser {
            public function __construct(private readonly Throwable $throwable)
            {
            }

            /**
             * @return array<\PhpParser\Node\Stmt>|null
             */
            public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
            {
                throw $this->throwable;
            }

            /**
             * @return array<\PhpParser\Token>
             */
            public function getTokens(): array
            {
                return [];
            }
        };
        $this->forceCachedParser($validator, $throwingParser);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('generated-failure');

        $validator->generated('<?php echo 1;', $context);
    }

    public function testTemplateSegmentsRethrowNonPhpParserErrors(): void
    {
        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: '<?= $value ?>',
            debug: true,
        );
        $document = new DocumentNode([
            new OutputNode('$value', true, OutputContext::HTML, 1, 1),
        ]);

        $validator = new PhpSyntaxValidator();
        $throwingParser = new class (new RuntimeException('segment-failure')) implements PhpAstParser {
            public function __construct(private readonly Throwable $throwable)
            {
            }

            /**
             * @return array<\PhpParser\Node\Stmt>|null
             */
            public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
            {
                throw $this->throwable;
            }

            /**
             * @return array<\PhpParser\Token>
             */
            public function getTokens(): array
            {
                return [];
            }
        };
        $this->forceCachedParser($validator, $throwingParser);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('segment-failure');

        $validator->templateSegments($document, $context);
    }

    public function testTemplateSegmentsParsesOutputExpressionsWhenCachedParserSet(): void
    {
        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: '<?= $value ?>',
            debug: true,
        );
        $document = new DocumentNode([
            new OutputNode('$value', true, OutputContext::HTML, 1, 1),
        ]);

        $validator = new PhpSyntaxValidator();
        $spyParser = new class () implements PhpAstParser {
            public int $parseCalls = 0;

            /**
             * @return array<\PhpParser\Node\Stmt>
             */
            public function parse(string $code, ?ErrorHandler $errorHandler = null): array
            {
                $this->parseCalls++;

                return [];
            }

            /**
             * @return array<\PhpParser\Token>
             */
            public function getTokens(): array
            {
                return [];
            }
        };
        $this->forceCachedParser($validator, $spyParser);

        $validator->templateSegments($document, $context);

        $this->assertSame(1, $spyParser->parseCalls);
    }

    public function testTemplateSegmentsSkipBooleanAndStringAttributeParts(): void
    {
        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: '<div disabled title="plain"></div>',
            debug: true,
        );

        $document = new DocumentNode([
            new ElementNode(
                tag: 'div',
                attributes: [
                    new AttributeNode('disabled', AttributeValue::boolean(), 1, 1),
                    new AttributeNode('title', AttributeValue::parts(['plain']), 1, 1),
                ],
                children: [],
                selfClosing: false,
                line: 1,
                column: 1,
            ),
        ]);

        $validator = new PhpSyntaxValidator();
        $spyParser = new class () implements PhpAstParser {
            public int $parseCalls = 0;

            /**
             * @return array<\PhpParser\Node\Stmt>
             */
            public function parse(string $code, ?ErrorHandler $errorHandler = null): array
            {
                $this->parseCalls++;

                return [];
            }

            /**
             * @return array<\PhpParser\Token>
             */
            public function getTokens(): array
            {
                return [];
            }
        };
        $this->forceCachedParser($validator, $spyParser);

        $validator->templateSegments($document, $context);

        $this->assertSame(0, $spyParser->parseCalls);
    }

    public function testNodeSegmentsHandleBooleanAttributePartsViaReflection(): void
    {
        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: '<div disabled></div>',
            debug: true,
        );

        $element = new ElementNode(
            tag: 'div',
            attributes: [
                new AttributeNode('disabled', AttributeValue::boolean(), 1, 1),
            ],
            children: [],
            selfClosing: false,
            line: 1,
            column: 1,
        );

        $validator = new PhpSyntaxValidator();
        $spyParser = new class () implements PhpAstParser {
            public int $parseCalls = 0;

            /**
             * @return array<\PhpParser\Node\Stmt>
             */
            public function parse(string $code, ?ErrorHandler $errorHandler = null): array
            {
                $this->parseCalls++;

                return [];
            }

            /**
             * @return array<\PhpParser\Token>
             */
            public function getTokens(): array
            {
                return [];
            }
        };
        $method = new ReflectionMethod($validator, 'nodeSegments');
        $method->invoke($validator, $element, $context, $spyParser);

        $this->assertSame(0, $spyParser->parseCalls);
    }

    public function testTemplateSegmentsTraverseFragmentAndDirectiveNodes(): void
    {
        $context = new CompilationContext(
            templatePath: 'inline-template',
            source: '',
            debug: true,
        );

        $fragmentOutput = new OutputNode('$fragmentValue', true, OutputContext::HTML, 1, 1);
        $directiveOutput = new OutputNode('$directiveValue', true, OutputContext::HTML, 1, 1);
        $document = new DocumentNode([
            new FragmentNode([], [$fragmentOutput], 1, 1),
            new DirectiveNode('if', '$condition', [$directiveOutput], 1, 1),
        ]);

        $validator = new PhpSyntaxValidator();
        $spyParser = new class () implements PhpAstParser {
            public int $parseCalls = 0;

            /**
             * @return array<\PhpParser\Node\Stmt>
             */
            public function parse(string $code, ?ErrorHandler $errorHandler = null): array
            {
                $this->parseCalls++;

                return [];
            }

            /**
             * @return array<\PhpParser\Token>
             */
            public function getTokens(): array
            {
                return [];
            }
        };
        $this->forceCachedParser($validator, $spyParser);

        $validator->templateSegments($document, $context);

        $this->assertSame(2, $spyParser->parseCalls);
    }

    /**
     * @param \PhpParser\Parser|null $parser Parser instance to inject
     */
    private function forceCachedParser(PhpSyntaxValidator $validator, ?PhpAstParser $parser, bool $initialized = true): void
    {
        $parserProperty = new ReflectionProperty($validator, 'cachedParser');
        $parserProperty->setValue($validator, $parser);

        $initializedProperty = new ReflectionProperty($validator, 'parserInitialized');
        $initializedProperty->setValue($validator, $initialized);
    }

    private function hasPhpParserSupport(): bool
    {
        return class_exists(ParserFactory::class) && class_exists(Error::class);
    }
}

<?php
declare(strict_types=1);

namespace Sugar\Extension\FragmentCache\Directive;

use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\Helper\ExpressionValidator;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\RawPhpNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Directive\Enum\DirectiveType;
use Sugar\Core\Directive\Interface\DirectiveInterface;
use Sugar\Core\Directive\Interface\ElementClaimingDirectiveInterface;
use Sugar\Core\Util\Hash;
use Sugar\Extension\FragmentCache\Runtime\FragmentCacheHelper;

/**
 * Compiler for s:cache fragment caching directive.
 *
 * The directive wraps a fragment render with runtime cache lookup/set calls.
 * It supports:
 * - Bare usage: `<div s:cache>...</div>` (auto key + default TTL)
 * - Key expression: `<div s:cache="'key-' . $id">...</div>`
 * - Options array: `<div s:cache="['key' => $key, 'ttl' => 300]">...</div>`
 */
final readonly class FragmentCacheDirective implements DirectiveInterface, ElementClaimingDirectiveInterface
{
    /**
     * @param int|null $defaultTtl Default TTL in seconds; null is passed to the PSR-16 store
     */
    public function __construct(
        private ?int $defaultTtl = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function compile(Node $node, CompilationContext $context): array
    {
        if (!($node instanceof DirectiveNode)) {
            return [];
        }

        $suffix = Hash::short($node->line . ':' . $node->column . ':' . $node->expression, 10);
        $optionsVar = '$__sugarFragmentCacheOptions_' . $suffix;
        $keyVar = '$__sugarFragmentCacheKey_' . $suffix;
        $ttlVar = '$__sugarFragmentCacheTtl_' . $suffix;
        $hitVar = '$__sugarFragmentCacheHit_' . $suffix;
        $contentVar = '$__sugarFragmentCacheContent_' . $suffix;

        $expression = $this->normalizeExpression($node->expression);
        $fallbackKey = var_export($this->buildFallbackKey($node, $context), true);
        $defaultTtl = $this->defaultTtl === null ? 'null' : (string)$this->defaultTtl;

        $openingCode = sprintf(
            '%s = %s; %s = ' . FragmentCacheHelper::class . '::resolveKey(%s, %s); ' .
            '%s = ' . FragmentCacheHelper::class . '::resolveTtl(%s, %s); ' .
            '%s = ' . FragmentCacheHelper::class . '::get(%s); ' .
            'if (%s !== null) { echo %s; } else { ob_start(); try {',
            $optionsVar,
            $expression,
            $keyVar,
            $optionsVar,
            $fallbackKey,
            $ttlVar,
            $optionsVar,
            $defaultTtl,
            $hitVar,
            $keyVar,
            $hitVar,
            $hitVar,
        );

        $closingCode = sprintf(
            '} finally { %s = (string)ob_get_clean(); } ' .
            FragmentCacheHelper::class . '::set(%s, %s, %s); ' .
            'echo %s; }',
            $contentVar,
            $keyVar,
            $contentVar,
            $ttlVar,
            $contentVar,
        );

        $openNode = new RawPhpNode($openingCode, $node->line, $node->column);
        $openNode->inheritTemplatePathFrom($node);

        $closeNode = new RawPhpNode($closingCode, $node->line, $node->column);
        $closeNode->inheritTemplatePathFrom($node);

        return [$openNode, ...$node->children, $closeNode];
    }

    /**
     * The cache key expression is supplied via the `key` attribute:
     * <s-cache key="'homepage'"> or bare <s-cache> for an auto-generated key.
     * When no `key` attribute is present the expression defaults to '' which
     * normalizeExpression() treats as a null (auto-key) expression.
     */
    public function getElementExpressionAttribute(): string
    {
        return 'key';
    }

    /**
     * @inheritDoc
     */
    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }

    /**
     * Normalize s:cache expression to a PHP expression that can be evaluated.
     */
    private function normalizeExpression(string $expression): string
    {
        $trimmed = ExpressionValidator::normalizeRuntimeExpression(
            expression: $expression,
            emptyFallback: 'null',
        );

        if (strtolower($trimmed) === 'true') {
            return 'null';
        }

        return $trimmed;
    }

    /**
     * Build a stable fallback cache key for bare s:cache usage.
     */
    private function buildFallbackKey(DirectiveNode $node, CompilationContext $context): string
    {
        $templatePath = $node->getTemplatePath() ?? $context->templatePath;

        return Hash::make($templatePath . ':' . $node->line . ':' . $node->column);
    }
}

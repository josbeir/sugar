<?php
declare(strict_types=1);

namespace Sugar\Directive;

use Psr\SimpleCache\CacheInterface;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Compiler\CompilationContext;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Enum\DirectiveType;
use Sugar\Runtime\FragmentCacheHelper;
use Sugar\Util\Hash;

/**
 * Compiler for s:cache fragment caching directive.
 *
 * The directive wraps a fragment render with runtime cache lookup/set calls.
 * It supports:
 * - Bare usage: `<div s:cache>...</div>` (auto key + default TTL)
 * - Key expression: `<div s:cache="'key-' . $id">...</div>`
 * - Options array: `<div s:cache="['key' => $key, 'ttl' => 300]">...</div>`
 */
final readonly class FragmentCacheDirective implements DirectiveInterface
{
    /**
     * @param \Psr\SimpleCache\CacheInterface|null $fragmentCache Optional PSR-16 cache store
     * @param int|null $defaultTtl Default TTL in seconds; null delegates to cache backend defaults
     */
    public function __construct(
        private ?CacheInterface $fragmentCache = null,
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

        if (!$this->fragmentCache instanceof CacheInterface) {
            return $node->children;
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
            'if (%s !== null) { echo %s; } else { ob_start();',
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
            '%s = (string)ob_get_clean(); ' .
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
        $trimmed = trim($expression);

        if ($trimmed === '' || strtolower($trimmed) === 'true') {
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

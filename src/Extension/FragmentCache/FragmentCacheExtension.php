<?php
declare(strict_types=1);

namespace Sugar\Extension\FragmentCache;

use Psr\SimpleCache\CacheInterface;
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;
use Sugar\Extension\FragmentCache\Directive\FragmentCacheDirective;

/**
 * Registers fragment caching support for the s:cache directive.
 */
final readonly class FragmentCacheExtension implements ExtensionInterface
{
    /**
     * @param \Psr\SimpleCache\CacheInterface|null $fragmentCache PSR-16 fragment cache store
     * @param int|null $defaultTtl Default fragment cache TTL in seconds; null is passed to the PSR-16 store
     */
    public function __construct(
        private ?CacheInterface $fragmentCache,
        private ?int $defaultTtl = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function register(RegistrationContext $context): void
    {
        if ($this->fragmentCache instanceof CacheInterface) {
            $context->runtimeService(CacheInterface::class, $this->fragmentCache);
        }

        $context->directive('cache', new FragmentCacheDirective(defaultTtl: $this->defaultTtl));
    }
}

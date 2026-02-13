<?php
declare(strict_types=1);

namespace Sugar\Extension;

use Psr\SimpleCache\CacheInterface;
use Sugar\Directive\FragmentCacheDirective;

/**
 * Registers fragment caching support for the s:cache directive.
 */
final readonly class FragmentCacheExtension implements ExtensionInterface
{
    /**
     * @param \Psr\SimpleCache\CacheInterface $fragmentCache PSR-16 fragment cache store
     * @param int|null $defaultTtl Default fragment cache TTL in seconds; null delegates to cache backend defaults
     */
    public function __construct(
        private CacheInterface $fragmentCache,
        private ?int $defaultTtl = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function register(RegistrationContext $context): void
    {
        $context->directive('cache', new FragmentCacheDirective(
            fragmentCache: $this->fragmentCache,
            defaultTtl: $this->defaultTtl,
        ));
    }
}

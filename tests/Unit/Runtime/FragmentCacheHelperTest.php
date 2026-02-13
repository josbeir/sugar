<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Runtime;

use DateInterval;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use stdClass;
use Sugar\Runtime\FragmentCacheHelper;
use Sugar\Runtime\RuntimeEnvironment;
use Sugar\Tests\Helper\Stub\ArraySimpleCache;

final class FragmentCacheHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        RuntimeEnvironment::clearFragmentCache();
        parent::tearDown();
    }

    public function testResolveKeyUsesFallbackForBareDirective(): void
    {
        $this->assertSame('fallback', FragmentCacheHelper::resolveKey(true, 'fallback'));
        $this->assertSame('fallback', FragmentCacheHelper::resolveKey(null, 'fallback'));
    }

    public function testResolveKeySupportsArrayOptions(): void
    {
        $this->assertSame('users', FragmentCacheHelper::resolveKey(['key' => 'users'], 'fallback'));
    }

    public function testResolveKeySupportsScalarAndFallsBackForInvalidValues(): void
    {
        $this->assertSame('users-1', FragmentCacheHelper::resolveKey('users-1', 'fallback'));
        $this->assertSame('fallback', FragmentCacheHelper::resolveKey(['key' => ['invalid']], 'fallback'));
        $this->assertSame('fallback', FragmentCacheHelper::resolveKey(['key' => '   '], 'fallback'));
        $this->assertSame('fallback', FragmentCacheHelper::resolveKey(new stdClass(), 'fallback'));
    }

    public function testResolveTtlUsesDefaultAndOptions(): void
    {
        $this->assertNull(FragmentCacheHelper::resolveTtl(null));
        $this->assertSame(120, FragmentCacheHelper::resolveTtl(null, 120));
        $this->assertSame(300, FragmentCacheHelper::resolveTtl(['ttl' => 300], 120));
        $this->assertSame(45, FragmentCacheHelper::resolveTtl(['ttl' => '45'], 120));
        $this->assertSame(120, FragmentCacheHelper::resolveTtl(['ttl' => 'not-numeric'], 120));
        $this->assertSame(0, FragmentCacheHelper::resolveTtl(['ttl' => 0], 120));
    }

    public function testGetReturnsNullWhenNoStoreConfigured(): void
    {
        $this->assertNull(FragmentCacheHelper::get('key'));
    }

    public function testSetAndGetUseConfiguredStore(): void
    {
        $store = new ArraySimpleCache();

        RuntimeEnvironment::setFragmentCache($store);

        FragmentCacheHelper::set('users', '<p>cached</p>', 600);

        $this->assertSame('<p>cached</p>', FragmentCacheHelper::get('users'));
        $this->assertSame(600, $store->ttls()['sugar_fragment:users']);
    }

    public function testGetReturnsNullWhenCachedValueIsNotString(): void
    {
        $store = new ArraySimpleCache();
        $store->set('sugar_fragment:users', ['not-string']);

        RuntimeEnvironment::setFragmentCache($store);

        $this->assertNull(FragmentCacheHelper::get('users'));
    }

    public function testGetReturnsNullWhenStoreThrows(): void
    {
        RuntimeEnvironment::setFragmentCache(new class implements CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                throw new RuntimeException('boom');
            }

            public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
            {
                return true;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function clear(): bool
            {
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            /**
             * @param iterable<string, mixed> $values
             */
            public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return false;
            }
        });

        $this->assertNull(FragmentCacheHelper::get('users'));
    }

    public function testSetSwallowsStoreExceptions(): void
    {
        $this->expectNotToPerformAssertions();

        RuntimeEnvironment::setFragmentCache(new class implements CacheInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                return null;
            }

            public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
            {
                throw new RuntimeException('boom');
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function clear(): bool
            {
                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                return [];
            }

            /**
             * @param iterable<string, mixed> $values
             */
            public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
            {
                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                return true;
            }

            public function has(string $key): bool
            {
                return false;
            }
        });

        FragmentCacheHelper::set('users', '<p>cached</p>', 100);
    }
}

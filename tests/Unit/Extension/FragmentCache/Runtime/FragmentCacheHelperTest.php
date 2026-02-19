<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension\FragmentCache\Runtime;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use stdClass;
use Sugar\Core\Runtime\RuntimeEnvironment;
use Sugar\Extension\FragmentCache\Runtime\FragmentCacheHelper;
use Sugar\Tests\Helper\Stub\ArraySimpleCache;

final class FragmentCacheHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        RuntimeEnvironment::clear();
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
        $this->assertSame(
            'users-2',
            FragmentCacheHelper::resolveKey(new class {
                public function __toString(): string
                {
                    return 'users-2';
                }
            }, 'fallback'),
        );
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
        $this->assertSame(120, FragmentCacheHelper::resolveTtl(['ttl' => -5], 120));
        $this->assertNull(FragmentCacheHelper::resolveTtl(['ttl' => '-5']));
    }

    public function testGetReturnsNullWhenNoStoreConfigured(): void
    {
        $this->assertNull(FragmentCacheHelper::get('key'));
    }

    public function testSetAndGetUseConfiguredStore(): void
    {
        $store = new ArraySimpleCache();

        RuntimeEnvironment::setService(CacheInterface::class, $store);

        FragmentCacheHelper::set('users', '<p>cached</p>', 600);

        $this->assertSame('<p>cached</p>', FragmentCacheHelper::get('users'));
        $this->assertSame(600, $store->ttls()['sugar_fragment:users']);
    }

    public function testGetReturnsNullWhenCachedValueIsNotString(): void
    {
        $store = new ArraySimpleCache();
        $store->set('sugar_fragment:users', ['not-string']);

        RuntimeEnvironment::setService(CacheInterface::class, $store);

        $this->assertNull(FragmentCacheHelper::get('users'));
    }

    public function testGetReturnsNullWhenStoreThrows(): void
    {
        $store = $this->createStub(CacheInterface::class);
        $store->method('get')->willThrowException(new RuntimeException('boom'));

        RuntimeEnvironment::setService(CacheInterface::class, $store);

        $this->assertNull(FragmentCacheHelper::get('users'));
    }

    public function testSetSwallowsStoreExceptions(): void
    {
        $this->expectNotToPerformAssertions();

        $store = $this->createStub(CacheInterface::class);
        $store->method('set')->willThrowException(new RuntimeException('boom'));

        RuntimeEnvironment::setService(CacheInterface::class, $store);

        FragmentCacheHelper::set('users', '<p>cached</p>', 100);
    }
}

<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Extension;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sugar\Extension\ExtensionInterface;
use Sugar\Extension\RegistrationContext;

/**
 * Tests for ExtensionInterface contract
 */
final class ExtensionInterfaceTest extends TestCase
{
    public function testInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(ExtensionInterface::class));
    }

    public function testInterfaceHasRegisterMethod(): void
    {
        $ref = new ReflectionClass(ExtensionInterface::class);

        $this->assertTrue($ref->hasMethod('register'));

        $method = $ref->getMethod('register');
        $this->assertCount(1, $method->getParameters());
        $this->assertSame('context', $method->getParameters()[0]->getName());
    }

    public function testAnonymousExtensionCanBeCreated(): void
    {
        $extension = new class implements ExtensionInterface {
            public function register(RegistrationContext $context): void
            {
            }
        };

        $this->assertInstanceOf(ExtensionInterface::class, $extension);
    }
}

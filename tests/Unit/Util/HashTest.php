<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Sugar\Util\Hash;

final class HashTest extends TestCase
{
    public function testShortGeneratesConsistentHash(): void
    {
        $data = 'test-data-123';
        $hash1 = Hash::short($data);
        $hash2 = Hash::short($data);

        $this->assertSame($hash1, $hash2, 'Same input should generate same hash');
    }

    public function testShortReturnsRequestedLength(): void
    {
        $data = 'test-data';

        $hash8 = Hash::short($data, 8);
        $hash16 = Hash::short($data, 16);
        $hash4 = Hash::short($data, 4);

        $this->assertSame(8, strlen($hash8));
        $this->assertSame(16, strlen($hash16));
        $this->assertSame(4, strlen($hash4));
    }

    public function testShortDefaultsToEightCharacters(): void
    {
        $data = 'test-data';
        $hash = Hash::short($data);

        $this->assertSame(8, strlen($hash));
    }

    public function testShortGeneratesDifferentHashForDifferentInput(): void
    {
        $hash1 = Hash::short('input-1');
        $hash2 = Hash::short('input-2');

        $this->assertNotSame($hash1, $hash2);
    }

    public function testMakeGeneratesConsistentHash(): void
    {
        $data = 'some-long-data-string';
        $hash1 = Hash::make($data);
        $hash2 = Hash::make($data);

        $this->assertSame($hash1, $hash2);
    }

    public function testMakeGeneratesFullLengthHash(): void
    {
        $data = 'test-data';
        $hash = Hash::make($data);

        // xxh3 (64-bit) produces 16 character hex string
        $this->assertSame(16, strlen($hash));
    }

    public function testMakeGeneratesDifferentHashForDifferentInput(): void
    {
        $hash1 = Hash::make('input-1');
        $hash2 = Hash::make('input-2');

        $this->assertNotSame($hash1, $hash2);
    }

    public function testShortUsesXxh3Algorithm(): void
    {
        $data = 'test';
        $hash = Hash::short($data, 16);

        // Verify it's using xxh3 by comparing with expected xxh3 output
        $expected = hash('xxh3', $data);

        $this->assertSame($expected, $hash);
    }

    public function testMakeUsesXxh3Algorithm(): void
    {
        $data = 'test-data-for-full-hash';
        $hash = Hash::make($data);

        // Verify it's using xxh3
        $expected = hash('xxh3', $data);

        $this->assertSame($expected, $hash);
    }

    public function testShortHandlesConcatenatedData(): void
    {
        // Test the actual use case in TagCompiler/IfContentCompiler
        $expression = '$tagName';
        $line = 42;
        $column = 8;

        $hash1 = Hash::short($expression . $line . $column);
        $hash2 = Hash::short($expression . $line . $column);

        $this->assertSame($hash1, $hash2);
        $this->assertSame(8, strlen($hash1));
    }
}

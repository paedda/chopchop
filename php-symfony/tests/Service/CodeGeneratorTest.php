<?php

namespace App\Tests\Service;

use App\Repository\LinkRepository;
use App\Service\CodeGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CodeGenerator.
 *
 * The repository is mocked so these tests run without a database.
 */
class CodeGeneratorTest extends TestCase
{
    private CodeGenerator $generator;

    /** @var LinkRepository&MockObject */
    private LinkRepository $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(LinkRepository::class);
        $this->generator  = new CodeGenerator();
    }

    /**
     * A generated code must be exactly 6 characters from the base62 alphabet.
     */
    public function testGeneratedCodeIsBase62AndCorrectLength(): void
    {
        $this->repository
            ->method('findOneBy')
            ->willReturn(null); // no collision

        $code = $this->generator->generate($this->repository);

        $this->assertSame(6, strlen($code));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{6}$/', $code);
    }

    /**
     * Calling generate() multiple times must not return the same code every time
     * (probabilistic — the chance of 50 consecutive identical codes is negligible).
     */
    public function testGeneratedCodesAreRandom(): void
    {
        $this->repository
            ->method('findOneBy')
            ->willReturn(null);

        $codes = array_map(fn () => $this->generator->generate($this->repository), range(1, 50));
        $unique = array_unique($codes);

        $this->assertGreaterThan(1, count($unique), 'Expected multiple distinct codes across 50 calls');
    }

    /**
     * When the first candidate collides, the generator must retry and return a
     * code on the next attempt.
     */
    public function testRetriesOnCollision(): void
    {
        $link = new \stdClass(); // non-null value simulates an existing link

        // First call finds a collision; second call finds nothing.
        $this->repository
            ->expects($this->exactly(2))
            ->method('findOneBy')
            ->willReturnOnConsecutiveCalls($link, null);

        $code = $this->generator->generate($this->repository);

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{6}$/', $code);
    }

    /**
     * After MAX_RETRIES consecutive collisions the generator must throw rather
     * than loop forever.
     */
    public function testThrowsAfterMaxRetries(): void
    {
        $link = new \stdClass();

        // Every call returns a collision.
        $this->repository
            ->method('findOneBy')
            ->willReturn($link);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unique code/i');

        $this->generator->generate($this->repository);
    }
}

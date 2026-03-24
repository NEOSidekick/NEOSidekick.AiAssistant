<?php

namespace NEOSidekick\AiAssistant\Tests\Unit\Infrastructure;

use GuzzleHttp\Psr7\Uri;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ApiFacadeTest extends TestCase
{
    private ReflectionMethod $deduplicateMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deduplicateMethod = new ReflectionMethod(ApiFacade::class, 'deduplicateArrayOfUriStrings');
        $this->deduplicateMethod->setAccessible(true);
    }

    private function deduplicate(array $input): array
    {
        return $this->deduplicateMethod->invoke(null, $input);
    }

    /** @test */
    public function itReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], $this->deduplicate([]));
    }

    /** @test */
    public function itReturnsSingleUri(): void
    {
        $result = $this->deduplicate(['https://example.com/de/page.html']);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(Uri::class, $result[0]);
        $this->assertSame('/de/page.html', $result[0]->getPath());
    }

    /** @test */
    public function itDeduplicatesIdenticalUrls(): void
    {
        $result = $this->deduplicate([
            'https://example.com/de/page.html',
            'https://example.com/de/page.html',
            'https://example.com/de/page.html',
        ]);
        $this->assertCount(1, $result);
    }

    /**
     * Deduplication keys by path only — different hosts with the same path collapse.
     * The last occurrence wins.
     * @test
     */
    public function itDeduplicatesByPathIgnoringHost(): void
    {
        $result = $this->deduplicate([
            'https://alpha.com/de/page.html',
            'https://beta.com/de/page.html',
        ]);
        $this->assertCount(1, $result);
        $this->assertSame('beta.com', $result[0]->getHost());
    }

    /**
     * Different paths are kept as separate entries.
     * @test
     */
    public function itKeepsDifferentPaths(): void
    {
        $result = $this->deduplicate([
            'https://example.com/de/page-a.html',
            'https://example.com/de/page-b.html',
        ]);
        $this->assertCount(2, $result);
        $paths = array_map(static fn (Uri $u) => $u->getPath(), $result);
        $this->assertContains('/de/page-a.html', $paths);
        $this->assertContains('/de/page-b.html', $paths);
    }

    /**
     * Scheme/port variations with the same path collapse.
     * @test
     */
    public function itDeduplicatesSchemeAndPortVariants(): void
    {
        $result = $this->deduplicate([
            'http://example.com/de/page.html',
            'https://example.com/de/page.html',
            'http://example.com:80/de/page.html',
            'https://example.com:443/de/page.html',
        ]);
        $this->assertCount(1, $result);
    }

    /**
     * A URL without a path is normalised to "/".
     * @test
     */
    public function itNormalisesEmptyPathToSlash(): void
    {
        $result = $this->deduplicate([
            'https://example.com',
            'https://example.com/',
        ]);
        $this->assertCount(1, $result);
        $this->assertSame('/', $result[0]->getPath());
    }
}

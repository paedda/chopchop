<?php

namespace App\Tests\Controller;

use App\Entity\Link;
use App\Repository\LinkRepository;
use App\Service\CodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for LinkController.
 *
 * All Doctrine and service dependencies are mocked, so these tests run
 * without a database or a running Symfony kernel.
 *
 * We instantiate the controller directly and call its action methods;
 * this keeps tests fast and focused on the controller logic itself.
 */
class LinkControllerTest extends TestCase
{
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;

    /** @var LinkRepository&MockObject */
    private LinkRepository $repo;

    /** @var CodeGenerator&MockObject */
    private CodeGenerator $codeGenerator;

    private \App\Controller\LinkController $controller;

    protected function setUp(): void
    {
        $this->em            = $this->createMock(EntityManagerInterface::class);
        $this->repo          = $this->createMock(LinkRepository::class);
        $this->codeGenerator = $this->createMock(CodeGenerator::class);

        $this->controller = new \App\Controller\LinkController(
            $this->em,
            $this->repo,
            $this->codeGenerator,
        );
    }

    // ── /health ──────────────────────────────────────────────────────────────

    public function testHealthReturns200WithCorrectPayload(): void
    {
        $response = $this->controller->health();

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('ok', $body['status']);
        $this->assertSame('php', $body['language']);
        $this->assertSame('symfony', $body['framework']);
    }

    // ── POST /shorten — validation ────────────────────────────────────────────

    public function testShortenReturns400ForMissingUrl(): void
    {
        $request  = $this->jsonRequest('POST', '{}');
        $response = $this->controller->shorten($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testShortenReturns400ForNonHttpUrl(): void
    {
        $request  = $this->jsonRequest('POST', '{"url":"ftp://example.com"}');
        $response = $this->controller->shorten($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testShortenReturns400ForInvalidUrl(): void
    {
        $request  = $this->jsonRequest('POST', '{"url":"not-a-url"}');
        $response = $this->controller->shorten($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testShortenReturns400ForInvalidCustomCode(): void
    {
        $request = $this->jsonRequest('POST', '{"url":"https://example.com","custom_code":"ab"}'); // too short
        $response = $this->controller->shorten($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testShortenReturns400ForExpiresInOutOfRange(): void
    {
        $request  = $this->jsonRequest('POST', '{"url":"https://example.com","expires_in":9999999}');
        $response = $this->controller->shorten($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testShortenReturns400ForNegativeExpiresIn(): void
    {
        $request  = $this->jsonRequest('POST', '{"url":"https://example.com","expires_in":-1}');
        $response = $this->controller->shorten($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testShortenReturns409WhenCustomCodeTaken(): void
    {
        $this->repo
            ->method('findOneBy')
            ->with(['code' => 'taken'])
            ->willReturn($this->makeLink('taken', 'https://example.com'));

        $request  = $this->jsonRequest('POST', '{"url":"https://example.com","custom_code":"taken"}');
        $response = $this->controller->shorten($request);

        $this->assertSame(409, $response->getStatusCode());
    }

    // ── POST /shorten — success ───────────────────────────────────────────────

    public function testShortenReturns201WithGeneratedCode(): void
    {
        $this->codeGenerator
            ->expects($this->once())
            ->method('generate')
            ->willReturn('abc123');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $request  = $this->jsonRequest('POST', '{"url":"https://example.com/path"}');
        $response = $this->controller->shorten($request);

        $this->assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('abc123', $body['code']);
        $this->assertSame('https://example.com/path', $body['url']);
        $this->assertStringEndsWith('/abc123', $body['short_url']);
        $this->assertNull($body['expires_at']);
    }

    public function testShortenReturns201WithCustomCode(): void
    {
        $this->repo->method('findOneBy')->willReturn(null); // code is free

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $request  = $this->jsonRequest('POST', '{"url":"https://example.com","custom_code":"my-link"}');
        $response = $this->controller->shorten($request);

        $this->assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('my-link', $body['code']);
    }

    public function testShortenSetsExpiresAtWhenExpiresInProvided(): void
    {
        $this->codeGenerator->method('generate')->willReturn('xyz789');
        $this->em->method('persist');
        $this->em->method('flush');

        $request  = $this->jsonRequest('POST', '{"url":"https://example.com","expires_in":3600}');
        $response = $this->controller->shorten($request);

        $this->assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertNotNull($body['expires_at']);
    }

    // ── GET /stats/:code ──────────────────────────────────────────────────────

    public function testStatsReturns404ForUnknownCode(): void
    {
        $this->repo->method('findByCodeWithClicks')->willReturn(null);

        $response = $this->controller->stats('unknown');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testStatsReturns200WithClickCount(): void
    {
        $link = $this->makeLink('abc123', 'https://example.com');

        $this->repo->method('findByCodeWithClicks')->willReturn($link);

        $response = $this->controller->stats('abc123');

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('abc123', $body['code']);
        $this->assertSame(0, $body['total_clicks']);
        $this->assertIsArray($body['recent_clicks']);
    }

    // ── GET /:code (redirect) ─────────────────────────────────────────────────

    public function testRedirectReturns404ForUnknownCode(): void
    {
        $this->repo->method('findOneBy')->willReturn(null);

        $response = $this->controller->redirectToUrl('unknown', Request::create('/unknown'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRedirectReturns410ForExpiredLink(): void
    {
        $link = $this->makeLink('old', 'https://example.com', expiresAt: new \DateTimeImmutable('-1 hour'));
        $this->repo->method('findOneBy')->willReturn($link);

        $response = $this->controller->redirectToUrl('old', Request::create('/old'));

        $this->assertSame(410, $response->getStatusCode());
    }

    public function testRedirectReturns301AndRecordsClick(): void
    {
        $link = $this->makeLink('abc123', 'https://example.com/destination');
        $this->repo->method('findOneBy')->willReturn($link);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $response = $this->controller->redirectToUrl('abc123', Request::create('/abc123'));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://example.com/destination', $response->headers->get('Location'));
    }

    public function testRedirectStillWorksForLinkWithFutureExpiry(): void
    {
        $link = $this->makeLink('future', 'https://example.com', expiresAt: new \DateTimeImmutable('+1 hour'));
        $this->repo->method('findOneBy')->willReturn($link);
        $this->em->method('persist');
        $this->em->method('flush');

        $response = $this->controller->redirectToUrl('future', Request::create('/future'));

        $this->assertSame(301, $response->getStatusCode());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Builds a Request with a JSON body.
     */
    private function jsonRequest(string $method, string $body): Request
    {
        return Request::create('/', $method, content: $body);
    }

    /**
     * Constructs a minimal Link entity without going through Doctrine.
     */
    private function makeLink(
        string $code,
        string $url,
        ?\DateTimeImmutable $expiresAt = null,
    ): Link {
        return (new Link())
            ->setCode($code)
            ->setUrl($url)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setExpiresAt($expiresAt);
    }
}

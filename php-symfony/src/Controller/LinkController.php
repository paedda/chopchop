<?php

namespace App\Controller;

use App\Entity\Click;
use App\Entity\Link;
use App\Repository\LinkRepository;
use App\Service\CodeGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles all four ChopChop API endpoints.
 *
 * Route priority (Symfony matches top-to-bottom within a controller):
 *   /health and /chop are static and matched before /{code}.
 *   /stats/{code} has two path segments so it never conflicts with /{code}.
 */
class LinkController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LinkRepository $linkRepository,
        private readonly CodeGenerator $codeGenerator,
    ) {}

    /**
     * Returns the health status of this backend.
     *
     * GET /health
     *
     * @return JsonResponse 200 {"status":"ok","language":"php","framework":"symfony"}
     */
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->respond([
            'status' => 'ok',
            'language' => 'php',
            'framework' => 'symfony',
        ]);
    }

    /**
     * Creates a short link and returns its details.
     *
     * POST /chop
     *
     * Request body (JSON):
     *   - url          string   required  Valid HTTP or HTTPS URL to shorten
     *   - custom_code  string   optional  3–20 alphanumeric characters or hyphens
     *   - expires_in   int      optional  Seconds until expiry; max 2 592 000 (30 days)
     *
     * @param Request $request Incoming POST request with a JSON body
     *
     * @return JsonResponse 201 on success
     *                      400 if the URL is invalid, custom_code format is wrong,
     *                          or expires_in is out of range
     *                      409 if the requested custom_code is already taken
     */
    #[Route('/chop', name: 'chop', methods: ['POST'])]
    public function chop(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $url = $data['url'] ?? null;
        $customCode = $data['custom_code'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        $parsedHost = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        if (!$url || !is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url) || !$parsedHost || !str_contains($parsedHost, '.')) {
            return $this->respond(['error' => 'Invalid or missing URL'], Response::HTTP_BAD_REQUEST);
        }

        if ($customCode !== null) {
            if (!is_string($customCode) || !preg_match('/^[a-zA-Z0-9\-]{3,20}$/', $customCode)) {
                return $this->respond(
                    ['error' => 'custom_code must be 3–20 alphanumeric characters or hyphens'],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            if ($this->linkRepository->findOneBy(['code' => $customCode]) !== null) {
                return $this->respond(['error' => 'Custom code already taken'], Response::HTTP_CONFLICT);
            }

            $code = $customCode;
        } else {
            $code = $this->codeGenerator->generate($this->linkRepository);
        }

        $expiresAt = null;
        if ($expiresIn !== null) {
            if (!is_int($expiresIn) || $expiresIn <= 0 || $expiresIn > 2592000) {
                return $this->respond(
                    ['error' => 'expires_in must be a positive integer no greater than 2592000'],
                    Response::HTTP_BAD_REQUEST,
                );
            }
            $expiresAt = new \DateTimeImmutable("+{$expiresIn} seconds");
        }

        $link = (new Link())
            ->setCode($code)
            ->setUrl($url)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setExpiresAt($expiresAt);

        $this->em->persist($link);
        $this->em->flush();

        return $this->respond([
            'code' => $link->getCode(),
            'short_url' => $request->getSchemeAndHttpHost() . '/' . $link->getCode(),
            'url' => $link->getUrl(),
            'created_at' => $link->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expires_at' => $link->getExpiresAt()?->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    /**
     * Returns click statistics for a short link.
     *
     * GET /stats/{code}
     *
     * Clicks are fetched via a single JOIN query (no N+1). The response
     * includes all-time total_clicks and the 10 most recent clicks, newest first.
     *
     * @param string $code Short code to look up
     *
     * @return JsonResponse 200 with link metadata and click stats
     *                      404 if the code does not exist
     */
    #[Route('/stats/{code}', name: 'stats', methods: ['GET'])]
    public function stats(string $code): JsonResponse
    {
        $link = $this->linkRepository->findByCodeWithClicks($code);

        if ($link === null) {
            return $this->respond(['error' => 'Link not found'], Response::HTTP_NOT_FOUND);
        }

        $recentClicks = [];
        foreach ($link->getClicks() as $click) {
            $recentClicks[] = [
                'clicked_at' => $click->getClickedAt()->format(\DateTimeInterface::ATOM),
                'referer' => $click->getReferer(),
                'user_agent' => $click->getUserAgent(),
            ];
        }

        return $this->respond([
            'code' => $link->getCode(),
            'url' => $link->getUrl(),
            'created_at' => $link->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expires_at' => $link->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'total_clicks' => count($link->getClicks()),
            'recent_clicks' => array_slice(array_reverse($recentClicks), 0, 10),
        ]);
    }

    /**
     * Redirects to the original URL and records a click.
     *
     * GET /{code}
     *
     * The click is persisted before the redirect so that the record is never
     * lost even if the client drops the connection mid-response.
     *
     * @param string  $code    Short code to resolve
     * @param Request $request Used to capture click metadata (IP, user-agent, referer)
     *
     * @return Response 301 redirect to the original URL
     *                  404 if the code does not exist
     *                  410 if the link has passed its expiry time
     */
    private function respond(mixed $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->setEncodingOptions($response->getEncodingOptions() | \JSON_UNESCAPED_SLASHES);
        return $response;
    }

    #[Route('/{code}', name: 'redirect', methods: ['GET'])]
    public function redirectToUrl(string $code, Request $request): Response
    {
        $link = $this->linkRepository->findOneBy(['code' => $code]);

        if ($link === null) {
            return $this->respond(['error' => 'Link not found'], Response::HTTP_NOT_FOUND);
        }

        if ($link->getExpiresAt() !== null && $link->getExpiresAt() < new \DateTimeImmutable()) {
            return $this->respond(['error' => 'Link has expired'], Response::HTTP_GONE);
        }

        $click = (new Click())
            ->setLink($link)
            ->setClickedAt(new \DateTimeImmutable())
            ->setIpAddress($request->getClientIp())
            ->setUserAgent($request->headers->get('User-Agent'))
            ->setReferer($request->headers->get('Referer'));

        $this->em->persist($click);
        $this->em->flush();

        return $this->redirect($link->getUrl(), Response::HTTP_MOVED_PERMANENTLY);
    }
}

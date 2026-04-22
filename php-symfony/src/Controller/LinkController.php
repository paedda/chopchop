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

class LinkController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LinkRepository $linkRepository,
        private readonly CodeGenerator $codeGenerator,
    ) {}

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'language' => 'php',
            'framework' => 'symfony',
        ]);
    }

    #[Route('/shorten', name: 'shorten', methods: ['POST'])]
    public function shorten(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $url = $data['url'] ?? null;
        $customCode = $data['custom_code'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;

        if (!$url || !is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return $this->json(['error' => 'Invalid or missing URL'], Response::HTTP_BAD_REQUEST);
        }

        if ($customCode !== null) {
            if (!is_string($customCode) || !preg_match('/^[a-zA-Z0-9\-]{3,20}$/', $customCode)) {
                return $this->json(
                    ['error' => 'custom_code must be 3–20 alphanumeric characters or hyphens'],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            if ($this->linkRepository->findOneBy(['code' => $customCode]) !== null) {
                return $this->json(['error' => 'Custom code already taken'], Response::HTTP_CONFLICT);
            }

            $code = $customCode;
        } else {
            $code = $this->codeGenerator->generate($this->linkRepository);
        }

        $expiresAt = null;
        if ($expiresIn !== null) {
            if (!is_int($expiresIn) || $expiresIn <= 0 || $expiresIn > 2592000) {
                return $this->json(
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

        return $this->json([
            'code' => $link->getCode(),
            'short_url' => $request->getSchemeAndHttpHost() . '/' . $link->getCode(),
            'url' => $link->getUrl(),
            'created_at' => $link->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expires_at' => $link->getExpiresAt()?->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('/stats/{code}', name: 'stats', methods: ['GET'])]
    public function stats(string $code): JsonResponse
    {
        $link = $this->linkRepository->findByCodeWithClicks($code);

        if ($link === null) {
            return $this->json(['error' => 'Link not found'], Response::HTTP_NOT_FOUND);
        }

        $recentClicks = [];
        foreach ($link->getClicks() as $click) {
            $recentClicks[] = [
                'clicked_at' => $click->getClickedAt()->format(\DateTimeInterface::ATOM),
                'referer' => $click->getReferer(),
                'user_agent' => $click->getUserAgent(),
            ];
        }

        return $this->json([
            'code' => $link->getCode(),
            'url' => $link->getUrl(),
            'created_at' => $link->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expires_at' => $link->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'total_clicks' => count($link->getClicks()),
            'recent_clicks' => array_slice(array_reverse($recentClicks), 0, 10),
        ]);
    }

    #[Route('/{code}', name: 'redirect', methods: ['GET'])]
    public function redirectToUrl(string $code, Request $request): Response
    {
        $link = $this->linkRepository->findOneBy(['code' => $code]);

        if ($link === null) {
            return $this->json(['error' => 'Link not found'], Response::HTTP_NOT_FOUND);
        }

        if ($link->getExpiresAt() !== null && $link->getExpiresAt() < new \DateTimeImmutable()) {
            return $this->json(['error' => 'Link has expired'], Response::HTTP_GONE);
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

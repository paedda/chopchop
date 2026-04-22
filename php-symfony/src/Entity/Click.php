<?php

namespace App\Entity;

use App\Repository\ClickRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Records a single visit to a short link, stored in the `clicks` table.
 *
 * A click is created by the redirect endpoint immediately before the 301
 * response is sent. ip_address, user_agent, and referer are all optional
 * and sourced from the incoming HTTP request headers.
 */
#[ORM\Entity(repositoryClass: ClickRepository::class)]
#[ORM\Table(name: 'clicks')]
class Click
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Link::class, inversedBy: 'clicks')]
    #[ORM\JoinColumn(nullable: false)]
    private Link $link;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $clickedAt;

    /** IPv4 or IPv6 address of the visitor, max 45 chars to accommodate IPv6. */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $userAgent = null;

    /** Value of the HTTP Referer header sent by the visitor's browser. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $referer = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLink(): Link
    {
        return $this->link;
    }

    public function setLink(Link $link): static
    {
        $this->link = $link;
        return $this;
    }

    public function getClickedAt(): \DateTimeImmutable
    {
        return $this->clickedAt;
    }

    public function setClickedAt(\DateTimeImmutable $clickedAt): static
    {
        $this->clickedAt = $clickedAt;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getReferer(): ?string
    {
        return $this->referer;
    }

    public function setReferer(?string $referer): static
    {
        $this->referer = $referer;
        return $this;
    }
}

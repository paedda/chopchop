<?php

namespace App\Entity;

use App\Repository\LinkRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents a shortened URL, stored in the `links` table.
 *
 * A link is identified by its unique short code. It optionally carries an
 * expiry timestamp; a null expires_at means the link never expires.
 * The one-to-many relation to Click is lazy by default — use
 * LinkRepository::findByCodeWithClicks() when you need clicks in the same query.
 */
#[ORM\Entity(repositoryClass: LinkRepository::class)]
#[ORM\Table(name: 'links')]
class Link
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Unique short code, max 10 characters, base62 alphabet or user-supplied. */
    #[ORM\Column(length: 10, unique: true)]
    private string $code;

    /** The full original URL this link redirects to. */
    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    /** Null means the link never expires. */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /** @var Collection<int, Click> */
    #[ORM\OneToMany(targetEntity: Click::class, mappedBy: 'link')]
    private Collection $clicks;

    public function __construct()
    {
        $this->clicks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    /**
     * @return Collection<int, Click>
     */
    public function getClicks(): Collection
    {
        return $this->clicks;
    }
}

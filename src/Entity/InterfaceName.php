<?php

namespace App\Entity;

use App\Repository\InterfaceNameRepository;
use App\Validator\ViewsAllowedForInterfaceName;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InterfaceNameRepository::class)]
#[ORM\Table(name: 'interface_name')]
#[ViewsAllowedForInterfaceName]
class InterfaceName
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(
        pattern: '/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?$/',
        message: 'Name must be a valid DNS label (letters, digits, hyphens; no leading/trailing hyphen)'
    )]
    private string $name = '';

    #[ORM\ManyToOne(targetEntity: Domain::class, inversedBy: 'interfaceNames')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: NetworkInterface::class, inversedBy: 'names')]
    #[ORM\JoinColumn(nullable: false)]
    private ?NetworkInterface $networkInterface = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $ttl = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isCanonical = false;

    #[ORM\ManyToMany(targetEntity: DnsView::class)]
    #[ORM\JoinTable(name: 'interface_name_dns_view')]
    private Collection $views;

    public function __construct()
    {
        $this->views = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDomain(): ?Domain { return $this->domain; }
    public function setDomain(?Domain $domain): static { $this->domain = $domain; return $this; }

    public function getNetworkInterface(): ?NetworkInterface { return $this->networkInterface; }
    public function setNetworkInterface(?NetworkInterface $networkInterface): static { $this->networkInterface = $networkInterface; return $this; }

    public function getTtl(): ?int { return $this->ttl; }
    public function setTtl(?int $ttl): static { $this->ttl = $ttl; return $this; }

    public function isCanonical(): bool { return $this->isCanonical; }
    public function setIsCanonical(bool $isCanonical): static { $this->isCanonical = $isCanonical; return $this; }

    public function getViews(): Collection { return $this->views; }

    public function addView(DnsView $view): static
    {
        if (!$this->views->contains($view)) {
            $this->views->add($view);
        }
        return $this;
    }

    public function removeView(DnsView $view): static
    {
        $this->views->removeElement($view);
        return $this;
    }

    public function getFullyQualifiedName(): string
    {
        if (!$this->domain) {
            return $this->name;
        }
        return $this->name . '.' . $this->domain->getName();
    }

    public function __toString(): string { return $this->getFullyQualifiedName(); }
}

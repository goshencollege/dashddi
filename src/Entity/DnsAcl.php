<?php

namespace App\Entity;

use App\Repository\DnsAclRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: DnsAclRepository::class)]
#[ORM\Table(name: 'dns_acl')]
#[UniqueEntity(fields: ['name'], message: 'This ACL name is already in use.')]
class DnsAcl
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9_\-.]+$/',
        message: 'ACL name may only contain letters, digits, hyphens, underscores, and dots.'
    )]
    private string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $entries = null;

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getEntries(): array { return $this->entries ?? []; }
    public function setEntries(array $entries): static { $this->entries = $entries ?: null; return $this; }

    #[Assert\Callback]
    public function validateEntries(ExecutionContextInterface $context): void
    {
        // Each entry is an IP, CIDR, ACL name, or negation thereof — nothing that could
        // inject a BIND directive via an unquoted semicolon, brace, or comment marker.
        $safe = '/^!?[A-Za-z0-9.:_\/\-]+$/';
        foreach ($this->getEntries() as $i => $entry) {
            if (!preg_match($safe, (string) $entry)) {
                $context->buildViolation('Entry must be an IP address, CIDR prefix, or ACL name (no spaces, semicolons, or braces).')
                    ->atPath("entries[$i]")
                    ->addViolation();
            }
        }
    }

    public function __toString(): string { return $this->name; }
}

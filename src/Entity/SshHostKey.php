<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ssh_host_key')]
#[ORM\UniqueConstraint(name: 'uniq_ssh_host_key_host_algorithm', columns: ['host_id', 'algorithm'])]
class SshHostKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Host::class, inversedBy: 'sshHostKeys')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Host $host;

    #[ORM\Column(length: 50)]
    private string $algorithm = '';

    /** Full normalized public key: "algorithm base64data" */
    #[ORM\Column(type: 'text')]
    private string $publicKey = '';

    public function getId(): ?int { return $this->id; }

    public function getHost(): Host { return $this->host; }
    public function setHost(Host $host): static { $this->host = $host; return $this; }

    public function getAlgorithm(): string { return $this->algorithm; }
    public function setAlgorithm(string $algorithm): static { $this->algorithm = $algorithm; return $this; }

    public function getPublicKey(): string { return $this->publicKey; }
    public function setPublicKey(string $key): static { $this->publicKey = $key; return $this; }

    public function getFingerprint(): ?string
    {
        $parts = explode(' ', trim($this->publicKey), 3);
        if (count($parts) < 2) {
            return null;
        }
        $raw = base64_decode($parts[1], true);
        if ($raw === false) {
            return null;
        }
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $raw, true)), '=');
    }
}

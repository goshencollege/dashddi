<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Repository\DeniedMacAddressRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DeniedMacAddressRepository::class)]
#[ORM\Table(name: 'denied_mac_address')]
#[UniqueEntity('macAddress')]
class DeniedMacAddress
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 17, unique: true)]
    #[Assert\Regex(
        pattern: '/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/',
        message: 'MAC address must be in format aa:bb:cc:dd:ee:ff'
    )]
    #[Assert\NotEqualTo(
        value: '00:00:00:00:00:00',
        message: 'The all-zeros MAC address cannot be denied.'
    )]
    private string $macAddress = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $reason = null;

    public function getId(): ?int { return $this->id; }

    public function getMacAddress(): string { return $this->macAddress; }
    public function setMacAddress(string $macAddress): static
    {
        $hex = preg_replace('/[^0-9a-fA-F]/', '', $macAddress);
        $this->macAddress = strlen($hex) === 12
            ? implode(':', str_split(strtolower($hex), 2))
            : strtolower(trim($macAddress));
        return $this;
    }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): static { $this->reason = $reason; return $this; }

    public function __toString(): string { return $this->macAddress; }
}

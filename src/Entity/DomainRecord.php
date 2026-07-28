<?php

namespace App\Entity;

use App\Entity\Trait\AuditableTrait;
use App\Enum\RecordType;
use App\Repository\DomainRecordRepository;
use App\Validator\NoCnameConflict;
use App\Validator\NoMultipleSpfTxt;
use App\Validator\TxtRecordValueValidator;
use App\Validator\ViewsAllowedForDomainRecord;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: DomainRecordRepository::class)]
#[ORM\Table(name: 'domain_record')]
#[ORM\Index(columns: ['domain_id'], name: 'idx_domain_record_domain_id')]
#[ORM\Index(columns: ['network_interface_id'], name: 'idx_domain_record_network_interface_id')]
#[ORM\Index(columns: ['virtual_ip_id'], name: 'idx_domain_record_virtual_ip_id')]
#[NoCnameConflict]
#[NoMultipleSpfTxt]
#[ViewsAllowedForDomainRecord]
class DomainRecord
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Domain::class, inversedBy: 'records')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Domain $domain = null;

    #[ORM\ManyToOne(targetEntity: NetworkInterface::class, inversedBy: 'domainRecords')]
    #[ORM\JoinColumn(nullable: true)]
    private ?NetworkInterface $networkInterface = null;

    #[ORM\ManyToOne(targetEntity: VirtualIp::class, inversedBy: 'domainRecords')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?VirtualIp $virtualIp = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Assert\Regex(
        pattern: '/^(@|\*(\.[a-zA-Z0-9_]([a-zA-Z0-9_\-]*[a-zA-Z0-9_])?)*\.?|[a-zA-Z0-9_]([a-zA-Z0-9_\-]*[a-zA-Z0-9_])?(\.[a-zA-Z0-9_]([a-zA-Z0-9_\-]*[a-zA-Z0-9_])?)*\.?)$/',
        message: 'Must be a valid DNS label (letters, digits, hyphens, underscores; dots allowed for subdomaining; *.sub wildcards allowed; @ for zone apex).'
    )]
    private string $hostname = '';

    #[ORM\Column(length: 10, enumType: RecordType::class)]
    private RecordType $type = RecordType::A;

    #[ORM\Column(type: 'text')]
    private string $value = '';

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $ttl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isCanonical = false;

    #[ORM\ManyToMany(targetEntity: DnsView::class)]
    #[ORM\JoinTable(name: 'domain_record_dns_view')]
    private Collection $views;

    public function __construct()
    {
        $this->views = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getDomain(): ?Domain { return $this->domain; }
    public function setDomain(?Domain $domain): static { $this->domain = $domain; return $this; }

    public function getNetworkInterface(): ?NetworkInterface { return $this->networkInterface; }
    public function setNetworkInterface(?NetworkInterface $networkInterface): static { $this->networkInterface = $networkInterface; return $this; }

    public function getVirtualIp(): ?VirtualIp { return $this->virtualIp; }
    public function setVirtualIp(?VirtualIp $virtualIp): static { $this->virtualIp = $virtualIp; return $this; }

    public function getHostname(): string { return $this->hostname; }
    public function setHostname(string $hostname): static { $this->hostname = $hostname; return $this; }

    public function getType(): RecordType { return $this->type; }
    public function setType(RecordType $type): static { $this->type = $type; return $this; }

    public function getValue(): string { return $this->value; }
    public function setValue(string $value): static { $this->value = $value; return $this; }

    public function getTtl(): ?int { return $this->ttl; }
    public function setTtl(?int $ttl): static { $this->ttl = $ttl; return $this; }

    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): static { $this->comment = $comment; return $this; }

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

    public function getFullyQualifiedHostname(): string
    {
        if ($this->domain) {
            if ($this->hostname === '@') {
                return $this->domain->getName();
            }
            return $this->hostname . '.' . $this->domain->getName();
        }
        return $this->hostname;
    }

    #[Assert\Callback]
    public function validateValue(ExecutionContextInterface $context, mixed $payload): void
    {
        if (($this->networkInterface === null && $this->virtualIp === null) || !in_array($this->type, [RecordType::A, RecordType::AAAA], true)) {
            if ($this->value === '') {
                $context->buildViolation('This value should not be blank.')
                    ->atPath('value')
                    ->addViolation();
                return;
            }
        }

        if ($this->value === '') {
            return;
        }

        if (str_contains($this->value, "\n") || str_contains($this->value, "\r")) {
            $context->buildViolation('Record value must not contain newlines.')
                ->atPath('value')
                ->addViolation();
            return;
        }

        match ($this->type) {
            RecordType::A    => $this->validateIpValue($context, FILTER_FLAG_IPV4, 'Must be a valid IPv4 address.'),
            RecordType::AAAA => $this->validateIpValue($context, FILTER_FLAG_IPV6, 'Must be a valid IPv6 address.'),
            RecordType::CNAME, RecordType::NS, RecordType::PTR => $this->validateHostnameValue($context),
            RecordType::MX   => $this->validateMxValue($context),
            RecordType::SRV  => $this->validateSrvValue($context),
            RecordType::CAA  => $this->validateCaaValue($context),
            RecordType::DS    => $this->validateDsValue($context),
            RecordType::TXT   => $this->validateTxtValue($context),
            RecordType::HTTPS => $this->validateHttpsValue($context),
            default           => null,
        };
    }

    private function validateIpValue(ExecutionContextInterface $context, int $flag, string $message): void
    {
        if (filter_var($this->value, FILTER_VALIDATE_IP, $flag) === false) {
            $context->buildViolation($message)->atPath('value')->addViolation();
        }
    }

    private function validateHostnameValue(ExecutionContextInterface $context): void
    {
        if (!$this->isValidHostnameTarget($this->value)) {
            $context->buildViolation('Must be a valid hostname or FQDN (e.g. "mail.example.com" or "mail.example.com.").')
                ->atPath('value')
                ->addViolation();
        }
    }

    private function validateMxValue(ExecutionContextInterface $context): void
    {
        if (!preg_match('/^(\d{1,5})\s+(\S+)$/', $this->value, $m)) {
            $context->buildViolation('MX value must be formatted as "<priority> <hostname>" (e.g. "10 mail.example.com").')
                ->atPath('value')
                ->addViolation();
            return;
        }
        if ((int) $m[1] > 65535) {
            $context->buildViolation('MX priority must be between 0 and 65535.')
                ->atPath('value')
                ->addViolation();
        }
        // '.' is a valid null MX target (RFC 7505)
        if ($m[2] !== '.' && !$this->isValidHostnameTarget($m[2])) {
            $context->buildViolation('MX hostname target must be a valid hostname or FQDN.')
                ->atPath('value')
                ->addViolation();
        }
    }

    private function validateSrvValue(ExecutionContextInterface $context): void
    {
        if (!preg_match('/^(\d{1,5})\s+(\d{1,5})\s+(\d{1,5})\s+(\S+)$/', $this->value, $m)) {
            $context->buildViolation('SRV value must be formatted as "<priority> <weight> <port> <target>" (e.g. "10 20 443 sip.example.com").')
                ->atPath('value')
                ->addViolation();
            return;
        }
        $labels = ['priority', 'weight', 'port'];
        foreach ([1, 2, 3] as $i) {
            if ((int) $m[$i] > 65535) {
                $context->buildViolation(sprintf('SRV %s must be between 0 and 65535.', $labels[$i - 1]))
                    ->atPath('value')
                    ->addViolation();
            }
        }
        // '.' means no service available
        if ($m[4] !== '.' && !$this->isValidHostnameTarget($m[4])) {
            $context->buildViolation('SRV target must be a valid hostname or FQDN.')
                ->atPath('value')
                ->addViolation();
        }
    }

    private function isValidHostnameTarget(string $value): bool
    {
        return (bool) preg_match(
            '/^(@|[a-zA-Z0-9_]([a-zA-Z0-9_\-]*[a-zA-Z0-9_])?(\.[a-zA-Z0-9_]([a-zA-Z0-9_\-]*[a-zA-Z0-9_])?)*\.?)$/',
            $value
        );
    }

    private function validateCaaValue(ExecutionContextInterface $context): void
    {
        if (!preg_match('/^(\d{1,3})\s+(issue|issuewild|iodef|contactemail|contactphone|issuemail)\s+"[^"]*"$/i', $this->value, $m)) {
            $context->buildViolation('CAA value must be formatted as \'<flags> <tag> "<value>"\' (e.g. \'0 issue "letsencrypt.org"\').')
                ->atPath('value')
                ->addViolation();
            return;
        }
        if ((int) $m[1] > 255) {
            $context->buildViolation('CAA flags must be between 0 and 255.')
                ->atPath('value')
                ->addViolation();
        }
    }

    private function validateDsValue(ExecutionContextInterface $context): void
    {
        if (!preg_match('/^\d+\s+\d+\s+\d+\s+[0-9a-fA-F]+$/', $this->value)) {
            $context->buildViolation('DS value must be formatted as "<keytag> <algorithm> <digest-type> <digest>" (e.g. "12345 13 2 ABCDEF...").')
                ->atPath('value')
                ->addViolation();
        }
    }

    private function validateTxtValue(ExecutionContextInterface $context): void
    {
        foreach (TxtRecordValueValidator::validate($this->hostname, $this->value) as $error) {
            $context->buildViolation($error)->atPath('value')->addViolation();
        }
    }

    private function validateHttpsValue(ExecutionContextInterface $context): void
    {
        // RFC 9460: <priority> <target> [<params>...]
        // Priority 0 = alias mode (target required), 1-65535 = service mode
        if (!preg_match('/^(\d{1,5})\s+\S+/', $this->value, $m)) {
            $context->buildViolation('HTTPS value must be formatted as "<priority> <target> [<params>]" (e.g. "1 . alpn=h2,h3" or "0 example.com.").')
                ->atPath('value')
                ->addViolation();
            return;
        }
        if ((int) $m[1] > 65535) {
            $context->buildViolation('HTTPS priority must be between 0 and 65535.')
                ->atPath('value')
                ->addViolation();
        }
    }
}

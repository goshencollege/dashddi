<?php

namespace App\Form;

use App\Entity\AddressBlock;
use App\Entity\DnsServer;
use App\Entity\DnsView;
use App\Entity\DnssecPolicy;
use App\Entity\Subnet;
use App\Entity\Tag;
use App\Entity\Vrf;
use App\Enum\BlockType;
use App\Repository\DnsViewRepository;
use App\Repository\TagRepository;
use App\Service\ReservedTagPrefixService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SubnetType extends AbstractType
{
    public function __construct(
        private readonly DnsViewRepository $viewRepo,
        private readonly ReservedTagPrefixService $reservedPrefixes,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'e.g. Office LAN'],
            ])
            ->add('isContainer', CheckboxType::class, [
                'label'    => 'Container subnet (organizes other subnets, not pushed to DHCP)',
                'required' => false,
            ])
            ->add('ipv4Cidr', TextType::class, [
                'required' => false,
                'label' => 'IPv4 CIDR',
                'attr' => ['placeholder' => '192.168.1.0/24'],
            ])
            ->add('ipv6Cidr', TextType::class, [
                'required' => false,
                'label' => 'IPv6 CIDR',
                'attr' => ['placeholder' => '2001:db8::/64'],
            ])
            ->add('gateway', TextType::class, [
                'required' => false,
                'attr' => ['placeholder' => '192.168.1.1'],
            ])
            ->add('vlan', IntegerType::class, [
                'required' => false,
                'label' => 'VLAN ID',
                'attr' => ['placeholder' => '100', 'min' => 1, 'max' => 4094],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('tags', EntityType::class, [
                'class'         => Tag::class,
                'choice_label'  => 'name',
                'multiple'      => true,
                'expanded'      => false,
                'required'      => false,
                'label'         => 'Tags',
                'by_reference'  => false,
                'query_builder' => fn(TagRepository $r) => $this->reservedPrefixes->excludeFromQuery(
                    $r->createQueryBuilder('t')->orderBy('t.name', 'ASC'), 't'
                ),
            ])
            ->add('vrf', EntityType::class, [
                'class'        => Vrf::class,
                'choice_label' => 'name',
                'placeholder'  => '-- None --',
                'required'     => false,
                'label'        => 'VRF',
            ])
            ->add('soaNameserver', TextType::class, [
                'label'    => 'Primary Nameserver (MNAME)',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. ns1.example.com'],
            ])
            ->add('soaEmail', TextType::class, [
                'label'    => 'Responsible Email (RNAME)',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. hostmaster@example.com'],
            ])
            ->add('soaRefresh', IntegerType::class, [
                'label'    => 'Refresh (seconds)',
                'required' => false,
            ])
            ->add('soaRetry', IntegerType::class, [
                'label'    => 'Retry (seconds)',
                'required' => false,
            ])
            ->add('soaExpire', IntegerType::class, [
                'label'    => 'Expire (seconds)',
                'required' => false,
            ])
            ->add('soaTtl', IntegerType::class, [
                'label'    => 'Minimum TTL (seconds)',
                'required' => false,
            ])
            ->add('views', EntityType::class, [
                'class'        => DnsView::class,
                'choices'      => $this->viewRepo->findBy([], ['name' => 'ASC']),
                'choice_label' => 'name',
                'multiple'     => true,
                'expanded'     => true,
                'required'     => false,
                'label'        => 'DNS Views (reverse zone)',
                'by_reference' => false,
            ])
            ->add('dnssecPolicy', EntityType::class, [
                'class'        => DnssecPolicy::class,
                'choice_label' => 'name',
                'placeholder'  => '— None —',
                'required'     => false,
                'label'        => 'DNSSEC Policy',
            ])
            ->add('leaseRetentionDays', IntegerType::class, [
                'label'    => 'DHCP Lease Retention (days)',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. 90 — leave blank to keep forever'],
                'help'     => 'Automatically delete lease log entries older than this many days.',
            ])
            ->add('ddnsEnabled', CheckboxType::class, [
                'label'    => 'Enable DDNS for reverse zone (allow-update in BIND)',
                'required' => false,
            ])
            ->add('ddnsDnsServer', EntityType::class, [
                'class'         => DnsServer::class,
                'choice_label'  => 'name',
                'placeholder'   => '— None —',
                'required'      => false,
                'label'         => 'DDNS Server',
                'help'          => 'Only servers with a DDNS algorithm configured are listed.',
                'query_builder' => fn($repo) => $repo->createQueryBuilder('s')
                    ->where('s.ddnsAlgorithm IS NOT NULL')
                    ->orderBy('s.name', 'ASC'),
            ])
            ->add('ddnsQualifyingSuffix', TextType::class, [
                'label'    => 'DDNS Qualifying Suffix',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. goshen.edu'],
                'help'     => 'Domain appended to dynamic client hostnames before DDNS registration. Enables ddns-send-updates for this subnet in the Kea config.',
            ]);

        if ($options['embed_blocks']) {
            $reserved = new AddressBlock();
            $reserved->setType(BlockType::Reserved);
            $fixed = new AddressBlock();
            $fixed->setType(BlockType::Fixed);

            $builder
                ->add('reservedBlock', EmbeddedBlockType::class, [
                    'mapped' => false,
                    'label'  => false,
                    'data'   => $reserved,
                ])
                ->add('fixedBlock', EmbeddedBlockType::class, [
                    'mapped' => false,
                    'label'  => false,
                    'data'   => $fixed,
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'   => Subnet::class,
            'embed_blocks' => false,
        ]);
        $resolver->setAllowedTypes('embed_blocks', 'bool');
    }
}

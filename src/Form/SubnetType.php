<?php

namespace App\Form;

use App\Entity\AddressBlock;
use App\Entity\DnsView;
use App\Entity\DnssecPolicy;
use App\Entity\Subnet;
use App\Entity\Tag;
use App\Entity\Vrf;
use App\Enum\BlockType;
use App\Repository\DnsViewRepository;
use App\Repository\TagRepository;
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
    public function __construct(private readonly DnsViewRepository $viewRepo) {}

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
                'query_builder' => fn(TagRepository $r) => $r->createQueryBuilder('t')
                    ->where('t.name NOT LIKE :prefix')
                    ->setParameter('prefix', 'snipeit:%')
                    ->orderBy('t.name', 'ASC'),
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

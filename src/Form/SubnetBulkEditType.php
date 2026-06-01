<?php

namespace App\Form;

use App\Entity\DnsView;
use App\Entity\DnssecPolicy;
use App\Entity\Tag;
use App\Repository\DnsViewRepository;
use App\Repository\TagRepository;
use App\Service\ReservedTagPrefixService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SubnetBulkEditType extends AbstractType
{
    public function __construct(
        private readonly DnsViewRepository $viewRepo,
        private readonly ReservedTagPrefixService $reservedPrefixes,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('vlan', IntegerType::class, [
                'required' => false,
                'label'    => 'VLAN ID',
                'attr'     => ['placeholder' => '100', 'min' => 1, 'max' => 4094],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('tags', EntityType::class, [
                'class'         => Tag::class,
                'choice_label'  => 'name',
                'multiple'      => true,
                'expanded'      => false,
                'required'      => false,
                'label'         => 'Tags',
                'query_builder' => fn(TagRepository $r) => $this->reservedPrefixes->excludeFromQuery(
                    $r->createQueryBuilder('t')->orderBy('t.name', 'ASC'), 't'
                ),
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
                'attr'     => ['placeholder' => '3600'],
            ])
            ->add('soaRetry', IntegerType::class, [
                'label'    => 'Retry (seconds)',
                'required' => false,
                'attr'     => ['placeholder' => '900'],
            ])
            ->add('soaExpire', IntegerType::class, [
                'label'    => 'Expire (seconds)',
                'required' => false,
                'attr'     => ['placeholder' => '604800'],
            ])
            ->add('soaTtl', IntegerType::class, [
                'label'    => 'Minimum TTL (seconds)',
                'required' => false,
                'attr'     => ['placeholder' => '3600'],
            ])
            ->add('views', EntityType::class, [
                'class'        => DnsView::class,
                'choices'      => $this->viewRepo->findBy([], ['name' => 'ASC']),
                'choice_label' => 'name',
                'multiple'     => true,
                'expanded'     => false,
                'required'     => false,
                'label'        => 'DNS Views',
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
                'attr'     => ['placeholder' => 'e.g. 90'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}

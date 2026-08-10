<?php

namespace App\Form;

use App\Entity\DnsServer;
use App\Entity\DnsView;
use App\Entity\DnssecPolicy;
use App\Entity\Domain;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DomainType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Domain Name',
                'attr'  => ['placeholder' => 'e.g. example.com'],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'attr'     => ['rows' => 3],
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
            ->add('defaultTtl', IntegerType::class, [
                'label'    => 'Default TTL (seconds)',
                'required' => false,
                'help'     => 'Sets the zone file\'s $TTL directive, used by records that don\'t specify their own TTL. Can be set higher than the Minimum TTL.',
            ])
            ->add('views', EntityType::class, [
                'class'        => DnsView::class,
                'choice_label' => 'name',
                'multiple'     => true,
                'expanded'     => true,
                'required'     => false,
                'label'        => 'BIND9 Views',
                'by_reference' => false,
            ])
            ->add('dnssecPolicy', EntityType::class, [
                'class'        => DnssecPolicy::class,
                'choice_label' => 'name',
                'placeholder'  => '— None —',
                'required'     => false,
                'label'        => 'DNSSEC Policy',
                'disabled'     => $options['locked_policy'],
            ])
            ->add('ddnsEnabled', CheckboxType::class, [
                'label'    => 'Enable DDNS (allow dynamic updates from Kea)',
                'required' => false,
            ])
            ->add('excludeFromInterfaces', CheckboxType::class, [
                'label'    => 'Exclude from interface DNS record forms',
                'required' => false,
                'help'     => 'When checked, this domain will not appear in the domain selector when adding or editing DNS records from an interface.',
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'    => Domain::class,
            'locked_policy' => false,
        ]);
        $resolver->setAllowedTypes('locked_policy', 'bool');
    }
}

<?php

namespace App\Form;

use App\Entity\DnsView;
use App\Entity\DnssecPolicy;
use App\Entity\Domain;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Domain::class]);
    }
}

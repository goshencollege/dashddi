<?php

namespace App\Form;

use App\Entity\DnssecPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DnssecPolicyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Policy Name',
                'attr'  => ['placeholder' => 'e.g. default, internal-policy'],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'attr'     => ['rows' => 2],
            ])
            ->add('dnskeyTtl', TextType::class, [
                'label'    => 'DNSKEY TTL',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. PT1H or 3600'],
            ])
            ->add('maxZoneTtl', TextType::class, [
                'label'    => 'Max Zone TTL',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. P1D or 86400'],
            ])
            ->add('signaturesValidity', TextType::class, [
                'label'    => 'Signatures Validity',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. P14D'],
            ])
            ->add('signaturesRefresh', TextType::class, [
                'label'    => 'Signatures Refresh',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. P5D'],
            ])
            ->add('publishSafety', TextType::class, [
                'label'    => 'Publish Safety',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. PT1H'],
            ])
            ->add('retireSafety', TextType::class, [
                'label'    => 'Retire Safety',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. PT1H'],
            ])
            ->add('purgeKeys', TextType::class, [
                'label'    => 'Purge Keys',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. P90D'],
            ])
            ->add('nsec3param', TextType::class, [
                'label'    => 'NSEC3 Parameters',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. 0 no 0'],
            ])
            ->add('keys', CollectionType::class, [
                'label'         => false,
                'entry_type'    => DnssecKeyEntryType::class,
                'entry_options' => ['label' => false],
                'allow_add'     => true,
                'allow_delete'  => true,
                'required'      => false,
            ])
            ->add('extraOptions', TextareaType::class, [
                'label'    => 'Additional Options',
                'required' => false,
                'attr'     => [
                    'rows'        => 4,
                    'placeholder' => "zone-propagation-delay PT5M;\nparent-ds-ttl 86400;",
                    'class'       => 'form-control font-monospace',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DnssecPolicy::class]);
    }
}

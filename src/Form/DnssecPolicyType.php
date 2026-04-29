<?php

namespace App\Form;

use App\Entity\DnssecPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
            ->add('dnskeyTtl', IntegerType::class, [
                'label'    => 'DNSKEY TTL (seconds)',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. 3600'],
            ])
            ->add('maxZoneTtl', IntegerType::class, [
                'label'    => 'Max Zone TTL (seconds)',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. 86400'],
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

<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DnssecKeyEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label'   => false,
                'choices' => ['KSK' => 'ksk', 'ZSK' => 'zsk', 'CSK' => 'csk'],
            ])
            ->add('algorithm', ChoiceType::class, [
                'label'   => false,
                'choices' => [
                    'ECDSA P-256 / SHA-256 (recommended)' => 'ecdsap256sha256',
                    'ECDSA P-384 / SHA-384'               => 'ecdsap384sha384',
                    'Ed25519'                              => 'ed25519',
                    'RSA / SHA-256'                        => 'rsasha256',
                    'RSA / SHA-512'                        => 'rsasha512',
                ],
            ])
            ->add('lifetime', TextType::class, [
                'label' => false,
                'attr'  => ['placeholder' => 'e.g. unlimited, P30D, P1Y'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}

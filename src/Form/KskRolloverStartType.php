<?php

namespace App\Form;

use App\Entity\DnsServer;
use App\Entity\Domain;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class KskRolloverStartType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('domain', EntityType::class, [
                'class'        => Domain::class,
                'choice_label' => 'name',
                'placeholder'  => '— select domain —',
                'constraints'  => [new NotBlank()],
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('dnsServer', EntityType::class, [
                'class'        => DnsServer::class,
                'choice_label' => 'name',
                'placeholder'  => '— select DNS server —',
                'constraints'  => [new NotBlank()],
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('algorithm', ChoiceType::class, [
                'choices' => [
                    'ECDSAP256SHA256 (recommended)' => 'ecdsap256sha256',
                    'ECDSAP384SHA384'               => 'ecdsap384sha384',
                    'ED25519'                       => 'ed25519',
                    'RSASHA256 (2048-bit)'          => 'rsasha256',
                ],
                'data'  => 'ecdsap256sha256',
                'attr'  => ['class' => 'form-select'],
            ])
            ->add('keyDirectory', TextType::class, [
                'label'    => 'Key Directory',
                'required' => false,
                'attr'     => ['class' => 'form-control', 'placeholder' => 'leave blank to use domain key-directory'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}

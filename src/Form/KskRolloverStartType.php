<?php

namespace App\Form;

use App\Entity\DnsServer;
use App\Entity\Domain;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
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
                'data'         => $options['first_server'],
                'constraints'  => [new NotBlank()],
                'attr'         => ['class' => 'form-select'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['first_server' => null]);
        $resolver->setAllowedTypes('first_server', ['null', DnsServer::class]);
    }
}

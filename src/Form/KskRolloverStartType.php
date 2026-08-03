<?php

namespace App\Form;

use App\Entity\DnsServer;
use App\Entity\DnssecPolicy;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class KskRolloverStartType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('zone', ChoiceType::class, [
                'choices'     => $options['zone_choices'],
                'data'        => $options['default_zone'],
                'placeholder' => '— select zone —',
                'required'    => true,
                'constraints' => [new NotBlank(message: 'Please select a zone.')],
                'attr'        => ['class' => 'form-select'],
            ])
            ->add('dnsServer', EntityType::class, [
                'class'        => DnsServer::class,
                'choice_label' => 'name',
                'data'         => $options['first_server'],
                'constraints'  => [new NotBlank()],
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('dnssecPolicy', EntityType::class, [
                'class'        => DnssecPolicy::class,
                'choice_label' => 'name',
                'placeholder'  => '— use zone\'s policy —',
                'required'     => false,
                'label'        => 'Algorithm Policy',
                'attr'         => ['class' => 'form-select'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'first_server' => null,
            'zone_choices' => [],
            'default_zone' => null,
        ]);
        $resolver->setAllowedTypes('first_server', ['null', DnsServer::class]);
        $resolver->setAllowedTypes('zone_choices', 'array');
        $resolver->setAllowedTypes('default_zone', ['null', 'string']);
    }
}

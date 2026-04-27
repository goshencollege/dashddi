<?php

namespace App\Form;

use App\Entity\Host;
use App\Entity\NetworkInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class HostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'e.g. web-server-01'],
            ])
            ->add('location', TextType::class, [
                'required' => false,
                'attr' => ['placeholder' => 'e.g. Rack A, Datacenter 1'],
            ]);

        if ($options['embed_interface']) {
            $builder->add('interface', NetworkInterfaceType::class, [
                'mapped' => false,
                'label'  => false,
                'data'   => new NetworkInterface(),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => Host::class,
            'embed_interface' => false,
        ]);
        $resolver->setAllowedTypes('embed_interface', 'bool');
    }
}

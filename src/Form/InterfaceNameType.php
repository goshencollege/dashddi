<?php

namespace App\Form;

use App\Entity\InterfaceName;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InterfaceNameType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'e.g. web-server-01'],
            ])
            ->add('dnsDomain', TextType::class, [
                'label' => 'DNS Domain',
                'attr' => ['placeholder' => 'e.g. example.com'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InterfaceName::class]);
    }
}

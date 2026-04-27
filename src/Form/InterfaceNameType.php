<?php

namespace App\Form;

use App\Entity\Domain;
use App\Entity\InterfaceName;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
            ->add('domain', EntityType::class, [
                'class'        => Domain::class,
                'choice_label' => 'name',
                'placeholder'  => '-- Select a domain --',
                'required'     => false,
                'label'        => 'Domain',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InterfaceName::class]);
    }
}

<?php

namespace App\Form;

use App\Entity\DeniedMacAddress;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DeniedMacAddressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('macAddress', TextType::class, [
                'label' => 'MAC Address',
                'attr'  => ['placeholder' => 'aa:bb:cc:dd:ee:ff'],
            ])
            ->add('reason', TextareaType::class, [
                'required' => false,
                'attr'     => ['rows' => 2, 'placeholder' => 'Optional reason'],
            ])
            ->add('enabled', CheckboxType::class, [
                'required' => false,
                'label'    => 'MAC Denied (uncheck to create as MAC Allowed)',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DeniedMacAddress::class]);
    }
}

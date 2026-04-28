<?php

namespace App\Form;

use App\Entity\AddressBlock;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmbeddedBlockType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('startIp', TextType::class, [
                'label'    => 'Start IP',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. 192.168.1.1', 'class' => 'font-monospace'],
            ])
            ->add('endIp', TextType::class, [
                'label'    => 'End IP',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. 192.168.1.10', 'class' => 'font-monospace'],
            ])
            ->add('label', TextType::class, [
                'required' => false,
                'attr'     => ['placeholder' => 'Optional label'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AddressBlock::class]);
    }
}

<?php

namespace App\Form;

use App\Entity\AddressBlock;
use App\Enum\BlockType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AddressBlockType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, [
                'class'        => BlockType::class,
                'choice_label' => fn(BlockType $t) => $t->label(),
                'label'        => 'Type',
            ])
            ->add('label', TextType::class, [
                'required' => false,
                'label'    => 'Label',
                'attr'     => ['placeholder' => 'e.g. DHCP pool, Management range'],
            ])
            ->add('startIp', TextType::class, [
                'label' => 'Start IP',
                'attr'  => ['placeholder' => 'e.g. 192.168.1.10', 'class' => 'font-monospace'],
            ])
            ->add('endIp', TextType::class, [
                'label' => 'End IP',
                'attr'  => ['placeholder' => 'e.g. 192.168.1.50', 'class' => 'font-monospace'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AddressBlock::class]);
    }
}

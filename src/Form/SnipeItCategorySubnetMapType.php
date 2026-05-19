<?php

namespace App\Form;

use App\Entity\SnipeItCategorySubnetMap;
use App\Entity\Subnet;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SnipeItCategorySubnetMapType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('snipeCategoryId', IntegerType::class, [
                'label' => 'Category ID',
                'attr'  => ['placeholder' => 'e.g. 3', 'min' => 1],
            ])
            ->add('snipeCategoryName', TextType::class, [
                'label' => 'Category Name',
                'attr'  => ['placeholder' => 'e.g. Access Points'],
            ])
            ->add('subnet', EntityType::class, [
                'class'        => Subnet::class,
                'choice_label' => fn(Subnet $s) => $s->getName() . ($s->getIpv4Cidr() ? ' (' . $s->getIpv4Cidr() . ')' : ''),
                'placeholder'  => '— select subnet —',
                'required'     => true,
                'label'        => 'Default Subnet',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SnipeItCategorySubnetMap::class]);
    }
}

<?php

namespace App\Form;

use App\Entity\SnipeItCategorySubnetMap;
use App\Entity\Subnet;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SnipeItCategorySubnetAssignType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('subnet', EntityType::class, [
            'class'        => Subnet::class,
            'choice_label' => fn(Subnet $s) => $s->getName() . ($s->getIpv4Cidr() ? ' (' . $s->getIpv4Cidr() . ')' : ''),
            'placeholder'  => '— none —',
            'required'     => false,
            'label'        => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SnipeItCategorySubnetMap::class]);
    }
}

<?php

namespace App\Form;

use App\Entity\AppSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AppSettingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('defaultLeaseRetentionDays', IntegerType::class, [
            'label'    => 'Default DHCP Lease Retention (days)',
            'required' => false,
            'attr'     => ['placeholder' => 'e.g. 365 — leave blank to keep forever'],
            'help'     => 'Fallback retention period for subnets with no per-subnet setting and for leases that do not match any known subnet. Leave blank to keep those records forever.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AppSetting::class]);
    }
}

<?php

namespace App\Form;

use App\Entity\AppSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AppSettingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('defaultLeaseRetentionDays', IntegerType::class, [
                'label'    => 'Default DHCP Lease Retention (days)',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. 365 — leave blank to keep forever'],
                'help'     => 'Fallback retention period for subnets with no per-subnet setting and for leases that do not match any known subnet. Leave blank to keep those records forever.',
            ])
            ->add('smtpHost', TextType::class, [
                'label'    => 'SMTP Host',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. smtp.example.com'],
            ])
            ->add('smtpPort', IntegerType::class, [
                'label'    => 'SMTP Port',
                'required' => false,
                'attr'     => ['placeholder' => '587'],
            ])
            ->add('smtpEncryption', ChoiceType::class, [
                'label'    => 'Encryption',
                'required' => false,
                'choices'  => [
                    'STARTTLS (port 587)' => 'tls',
                    'SSL/TLS (port 465)'  => 'ssl',
                    'None'                => 'none',
                ],
            ])
            ->add('smtpUsername', TextType::class, [
                'label'    => 'SMTP Username',
                'required' => false,
                'attr'     => ['autocomplete' => 'off'],
            ])
            ->add('smtpPassword', PasswordType::class, [
                'label'       => 'SMTP Password',
                'required'    => false,
                'always_empty' => false,
                'attr'        => ['autocomplete' => 'new-password'],
                'help'        => 'Leave blank to keep the existing password.',
            ])
            ->add('smtpFromEmail', EmailType::class, [
                'label'    => 'From Address',
                'required' => false,
                'attr'     => ['placeholder' => 'noreply@example.com'],
            ])
            ->add('smtpFromName', TextType::class, [
                'label'    => 'From Name',
                'required' => false,
                'attr'     => ['placeholder' => 'DashDDI'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AppSetting::class]);
    }
}

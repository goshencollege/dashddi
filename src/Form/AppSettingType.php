<?php

namespace App\Form;

use App\Entity\AppSetting;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AppSettingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('timezone', TimezoneType::class, [
                'label'       => 'Display Timezone',
                'required'    => false,
                'placeholder' => 'UTC (default)',
                'attr'        => ['class' => 'form-select'],
                'help'        => 'Timezone used for all date/time display throughout the application.',
            ])
            ->add('defaultLeaseRetentionDays', IntegerType::class, [
                'label'    => 'Default DHCP Lease Retention (days)',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. 365 — leave blank to keep forever'],
                'help'     => 'Fallback retention period for leases that do not match any known subnet. Leave blank to keep those records forever.',
            ])
            ->add('pushLogRetentionDays', IntegerType::class, [
                'label'    => 'Push Log Retention (days)',
                'required' => false,
                'attr'     => ['placeholder' => '30'],
                'help'     => 'How long to keep DNS/DHCP push log entries. Leave blank to keep forever.',
            ])
            ->add('deletedHostRetentionDays', IntegerType::class, [
                'label'    => 'Deleted Host/Interface Retention (days)',
                'required' => false,
                'attr'     => ['placeholder' => '90'],
                'help'     => 'How long to keep soft-deleted hosts and interfaces before permanently removing them. Leave blank to keep forever.',
            ])
            ->add('clearpassAuthLogRetentionDays', IntegerType::class, [
                'label'    => 'ClearPass Auth Log Retention (days)',
                'required' => false,
                'attr'     => ['placeholder' => '30'],
                'help'     => 'How long to keep ClearPass authentication log entries. Leave blank to keep forever.',
            ])
            ->add('switchPortLogRetentionDays', IntegerType::class, [
                'label'    => 'Switch Port Log Retention (days)',
                'required' => false,
                'attr'     => ['placeholder' => '90'],
                'help'     => 'How long to keep switch-port attachment history (from ClearPass auth logs and live switch scans). Leave blank to keep forever.',
            ])
            ->add('switchInfoMaxAgeDays', IntegerType::class, [
                'label'    => 'Switch Info Max Age (days)',
                'required' => false,
                'attr'     => ['placeholder' => '7'],
                'help'     => 'A device\'s cached switch IP/port (learned from ClearPass auth logs or a live switch scan) is shown, and can be acted on, only if it was last confirmed within this many days. Leave blank to always show/act on it regardless of age.',
            ])
            ->add('defaultNewSubnetLeaseRetentionDays', IntegerType::class, [
                'label'    => 'Default Retention for New Subnets (days)',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. 365 — leave blank for no default'],
                'help'     => 'Pre-filled on new subnets at creation time. Can be overridden per subnet. Leave blank to create subnets with no retention limit set.',
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
            ->add('activityLogRetentionDays', IntegerType::class, [
                'label'    => 'Activity Log Retention (days)',
                'required' => false,
                'attr'     => ['placeholder' => '90'],
                'help'     => 'How long to keep activity log entries in the database. Leave blank to keep forever.',
            ])
            ->add('syslogEnabled', CheckboxType::class, [
                'label'    => 'Enable remote syslog forwarding',
                'required' => false,
            ])
            ->add('syslogHost', TextType::class, [
                'label'    => 'Syslog Server Host',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. syslog.example.com or 192.168.1.10'],
            ])
            ->add('syslogPort', IntegerType::class, [
                'label'    => 'Syslog Port',
                'required' => false,
                'attr'     => ['placeholder' => '514'],
            ])
            ->add('syslogProtocol', ChoiceType::class, [
                'label'    => 'Syslog Protocol',
                'required' => false,
                'choices'  => [
                    'UDP' => 'udp',
                    'TCP' => 'tcp',
                ],
                'help' => 'TCP allows persistent connections and larger messages. UDP is simpler but limited to ~1024 bytes per message.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AppSetting::class]);
    }
}

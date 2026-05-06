<?php

namespace App\Form;

use App\Entity\ScheduledTask;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ScheduledTaskFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('cronExpression', TextType::class, [
                'label' => 'Cron Schedule',
                'attr'  => [
                    'placeholder'  => '0 2 * * *',
                    'class'        => 'form-control font-monospace',
                    'autocomplete' => 'off',
                ],
                'help'  => 'Five fields: minute hour day-of-month month day-of-week. Example: <code>0 2 * * *</code> = daily at 2:00 AM.',
                'help_html' => true,
            ])
            ->add('enabled', CheckboxType::class, [
                'label'    => 'Enabled',
                'required' => false,
                'help'     => 'When enabled, this task will run automatically according to the schedule.',
            ])
            ->add('notificationEmail', EmailType::class, [
                'label'    => 'Failure Notification Email',
                'required' => false,
                'attr'     => ['placeholder' => 'admin@example.com'],
                'help'     => 'If set, an email will be sent to this address when the task fails. Requires SMTP to be configured in Application Settings.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ScheduledTask::class]);
    }
}

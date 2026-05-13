<?php

namespace App\Form;

use App\Entity\RadiusClient;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RadiusClientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Display Name',
                'help'  => 'A friendly name for this client (e.g. "ClearPass Primary").',
            ])
            ->add('nasname', TextType::class, [
                'label' => 'IP Address / Network',
                'help'  => 'IPv4 address or CIDR block (e.g. 10.0.0.5 or 10.0.0.0/24).',
            ])
            ->add('shortname', TextType::class, [
                'label'    => 'Short Name',
                'required' => false,
                'help'     => 'Optional identifier used in FreeRADIUS logs.',
            ])
            ->add('secret', PasswordType::class, [
                'label'        => 'Shared Secret',
                'required'     => $options['is_new'],
                'always_empty' => false,
                'attr'         => ['autocomplete' => 'new-password'],
                'help'         => $options['is_new'] ? null : 'Leave blank to keep the existing secret.',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Description',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('enabled', CheckboxType::class, [
                'label'    => 'Enabled',
                'required' => false,
                'help'     => 'Disabled clients are excluded from the FreeRADIUS client list.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RadiusClient::class,
            'is_new'     => false,
        ]);
    }
}

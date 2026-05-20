<?php

namespace App\Form;

use App\Entity\ArubaSwitch;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ArubaSwitchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'    => 'Name',
                'required' => true,
            ])
            ->add('managementIp', TextType::class, [
                'label'    => 'Management IP',
                'required' => true,
                'attr'     => ['placeholder' => '192.168.1.1'],
                'help'     => 'IP address used to reach the switch. Must match the NAS-IP-Address reported by ClearPass.',
            ])
            ->add('username', TextType::class, [
                'label'    => 'Username',
                'required' => true,
                'attr'     => ['autocomplete' => 'off'],
            ])
            ->add('password', PasswordType::class, [
                'label'        => 'Password',
                'required'     => false,
                'always_empty' => false,
                'attr'         => ['autocomplete' => 'new-password'],
                'help'         => 'Used for REST API authentication. Leave blank to keep the existing password.',
            ])
            ->add('restApiVersion', TextType::class, [
                'label'    => 'REST API Version',
                'required' => false,
                'attr'     => ['placeholder' => 'v10.12'],
                'help'     => 'Aruba CX REST API version. Check your firmware release notes (e.g. v10.09, v10.10, v10.12).',
            ])
            ->add('verifyTls', CheckboxType::class, [
                'label'    => 'Verify TLS Certificate',
                'required' => false,
                'help'     => 'Disable if the switch uses a self-signed certificate (typical for management interfaces).',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Description',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ArubaSwitch::class]);
    }
}

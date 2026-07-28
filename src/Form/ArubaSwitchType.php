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
                'help'     => 'Recommended. Uncheck only if the switch uses a self-signed certificate that cannot be added to the server\'s trust store.',
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

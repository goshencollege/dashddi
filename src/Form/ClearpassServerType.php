<?php

namespace App\Form;

use App\Entity\ClearpassServer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClearpassServerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'e.g. Primary ClearPass'],
            ])
            ->add('apiUrl', UrlType::class, [
                'label' => 'API URL',
                'attr'  => ['placeholder' => 'https://clearpass.example.com'],
            ])
            ->add('clientId', TextType::class, [
                'label' => 'OAuth Client ID',
                'attr'  => ['placeholder' => 'DashDDI'],
            ])
            ->add('clientSecret', PasswordType::class, [
                'label'        => 'OAuth Client Secret',
                'required'     => false,
                'always_empty' => false,
                'attr'         => ['autocomplete' => 'new-password'],
            ])
            ->add('verifyTls', CheckboxType::class, [
                'label'    => 'Verify TLS certificate',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'attr'     => ['rows' => 2, 'placeholder' => 'Optional notes'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ClearpassServer::class]);
    }
}

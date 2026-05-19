<?php

namespace App\Form;

use App\Entity\SnipeItServer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SnipeItServerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'e.g. Primary Snipe-IT'],
            ])
            ->add('apiUrl', UrlType::class, [
                'label' => 'API URL',
                'attr'  => ['placeholder' => 'https://snipe.example.com'],
            ])
            ->add('apiKey', PasswordType::class, [
                'label'        => 'API Key',
                'required'     => false,
                'always_empty' => false,
                'attr'         => ['autocomplete' => 'new-password'],
                'help'         => 'Personal access token from Snipe-IT → Profile → API.',
            ])
            ->add('macCustomFields', TextType::class, [
                'label' => 'MAC Address Custom Field Names',
                'attr'  => ['placeholder' => 'MAC Address, Secondary MAC, MAC 2'],
                'help'  => 'Comma-separated display names of the Snipe-IT custom fields that store MAC addresses.',
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
        $resolver->setDefaults(['data_class' => SnipeItServer::class]);
    }
}

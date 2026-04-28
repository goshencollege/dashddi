<?php

namespace App\Form;

use App\Entity\DhcpServer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DhcpServerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'e.g. Primary DHCP'],
            ])
            ->add('hostname', TextType::class, [
                'label' => 'Hostname / IP',
                'attr'  => ['placeholder' => 'e.g. dhcp1.example.com or 10.0.0.5'],
            ])
            ->add('sshUser', TextType::class, [
                'label' => 'SSH User',
                'attr'  => ['placeholder' => 'root'],
            ])
            ->add('remotePath', TextType::class, [
                'label' => 'Remote Path',
                'attr'  => ['placeholder' => '/etc/kea'],
            ])
            ->add('sshKeyPath', TextType::class, [
                'label' => 'SSH Key Path (inside container)',
                'attr'  => ['placeholder' => '/var/www/.ssh/id_ed25519'],
            ])
            ->add('controlUrl', TextType::class, [
                'label'    => 'Control Agent URL',
                'required' => false,
                'attr'     => ['placeholder' => 'http://192.168.1.1:8000'],
            ])
            ->add('controlUser', TextType::class, [
                'label'    => 'Control Agent User',
                'required' => false,
                'attr'     => ['placeholder' => 'kea-api'],
            ])
            ->add('controlPassword', PasswordType::class, [
                'label'        => 'Control Agent Password',
                'required'     => false,
                'always_empty' => false,
                'attr'         => ['autocomplete' => 'new-password'],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'attr'     => ['rows' => 2, 'placeholder' => 'Optional notes'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DhcpServer::class]);
    }
}

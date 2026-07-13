<?php

namespace App\Form;

use App\Entity\DhcpServer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
            ->add('versionScope', ChoiceType::class, [
                'label'   => 'Push Versions',
                'choices' => [
                    'Both IPv4 and IPv6' => 'both',
                    'IPv4 only'          => 'v4',
                    'IPv6 only'          => 'v6',
                ],
            ])
            ->add('controlPort', IntegerType::class, [
                'label'    => 'Control Agent Port',
                'required' => false,
                'attr'     => ['placeholder' => '8000', 'min' => 1, 'max' => 65535],
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
            ])
            ->add('ddnsEnabled', CheckboxType::class, [
                'label'    => 'Deploy kea-dhcp-ddns.conf (Dynamic DNS)',
                'required' => false,
                'help'     => 'Generates and deploys kea-dhcp-ddns.conf to this server. Requires at least one subnet with a DDNS domain configured.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DhcpServer::class]);
    }
}

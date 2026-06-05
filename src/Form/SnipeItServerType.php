<?php

namespace App\Form;

use App\Entity\SnipeItServer;
use App\Entity\Subnet;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
                'attr'  => ['placeholder' => 'MAC Address, WiFi MAC Address:wifi, Management MAC:mgmt'],
                'help'  => 'Comma-separated Snipe-IT custom field display names. Optionally append :alias to set a short interface name (e.g. "WiFi MAC Address:wifi"). Without an alias, one is derived automatically.',
            ])
            ->add('vlanOverrideCustomField', TextType::class, [
                'label'    => 'VLAN Override Custom Field Name',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. VLAN Override'],
                'help'     => 'Optional. Display name of a Snipe-IT custom field containing a numeric VLAN ID. When set, overrides the category-based subnet assignment for individual assets.',
            ])
            ->add('defaultSubnet', EntityType::class, [
                'class'         => Subnet::class,
                'choice_label'  => fn(Subnet $s) => (string) $s,
                'query_builder' => fn($repo) => $repo->createQueryBuilder('s')->orderBy('s.name', 'ASC'),
                'placeholder'   => '— none —',
                'required'      => false,
                'label'         => 'Default Subnet',
                'help'          => 'Optional. Assigned to interfaces when neither the category map nor the VLAN override resolves to a subnet.',
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

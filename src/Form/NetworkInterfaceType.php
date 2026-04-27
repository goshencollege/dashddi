<?php

namespace App\Form;

use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NetworkInterfaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];

        $ipv4Choices = $isEdit
            ? ['Keep current assignment' => 'keep', 'Remove IPv4 address' => 'none', 'Specify IP' => 'select', 'Auto-assign next available' => 'auto']
            : ['No IPv4 address' => 'none', 'Specify IP' => 'select', 'Auto-assign next available' => 'auto'];

        $ipv6Choices = $isEdit
            ? ['Keep current assignment' => 'keep', 'Remove IPv6 address' => 'none', 'Specify IP' => 'select', 'Auto-assign (EUI-64 from MAC)' => 'auto']
            : ['No IPv6 address' => 'none', 'Specify IP' => 'select', 'Auto-assign (EUI-64 from MAC)' => 'auto'];

        $builder
            ->add('macAddress', TextType::class, [
                'label' => 'MAC Address',
                'attr' => ['placeholder' => 'e.g. aabbccddeeff  or  aa:bb:cc:dd:ee:ff  or  AA-BB-CC-DD-EE-FF'],
            ])
            ->add('subnet', EntityType::class, [
                'class' => Subnet::class,
                'choice_label' => fn($subnet) => (string) $subnet,
                'placeholder' => '-- Select a subnet --',
                'required' => false,
                'attr' => ['id' => 'interface_subnet'],
            ])
            ->add('ipv4Assignment', ChoiceType::class, [
                'mapped' => false,
                'label' => 'IPv4 Assignment',
                'choices' => $ipv4Choices,
                'expanded' => true,
                'data' => $isEdit ? 'keep' : 'none',
                'required' => false,
            ])
            ->add('ipv4AddressInput', TextType::class, [
                'mapped' => false,
                'label' => 'IPv4 Address',
                'required' => false,
                'attr' => [
                    'id' => 'ipv4_address_input',
                    'list' => 'available-ipv4-list',
                    'placeholder' => 'Select or type an IPv4 address',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('ipv6Assignment', ChoiceType::class, [
                'mapped' => false,
                'label' => 'IPv6 Assignment',
                'choices' => $ipv6Choices,
                'expanded' => true,
                'data' => $isEdit ? 'keep' : 'none',
                'required' => false,
            ])
            ->add('ipv6AddressInput', TextType::class, [
                'mapped' => false,
                'label' => 'IPv6 Address',
                'required' => false,
                'attr' => [
                    'id' => 'ipv6_address_input',
                    'list' => 'available-ipv6-list',
                    'placeholder' => 'Select or type an IPv6 address',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('names', CollectionType::class, [
                'entry_type' => InterfaceNameType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => 'DNS Names',
                'entry_options' => ['label' => false],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NetworkInterface::class,
            'is_edit'    => false,
        ]);
        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}

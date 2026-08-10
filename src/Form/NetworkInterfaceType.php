<?php

namespace App\Form;

use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
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
            ? ['Keep current assignment' => 'keep', 'Remove IPv6 address' => 'none', 'Specify IP' => 'select', 'Auto-assign (EUI-64 from MAC)' => 'auto', 'Auto-assign (last IPv4 octet)' => 'auto_v4']
            : ['No IPv6 address' => 'none', 'Specify IP' => 'select', 'Auto-assign (EUI-64 from MAC)' => 'auto', 'Auto-assign (last IPv4 octet)' => 'auto_v4'];

        $builder
            ->add('notes', TextareaType::class, [
                'label'    => 'Notes',
                'required' => false,
                'attr'     => ['rows' => 3, 'placeholder' => 'Free-text notes about this interface'],
            ])
            ->add('name', TextType::class, [
                'label'    => 'Interface Name',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. eth0, Management, WAN'],
            ])
            ->add('macAddress', TextType::class, [
                'label'      => 'MAC Address',
                'required'   => false,
                'empty_data' => '',
                'attr'       => ['placeholder' => 'e.g. aabbccddeeff  or  aa:bb:cc:dd:ee:ff  or  AA-BB-CC-DD-EE-FF (leave blank for 00:00:00:00:00:00)'],
            ]);

        if ($options['show_duid']) {
            $builder->add('duid', TextType::class, [
                'mapped'     => false,
                'label'      => 'DUID',
                'required'   => false,
                'data'       => $options['data']?->getHost()?->getDuidDisplay(),
                'attr'       => [
                    'class'       => 'font-monospace',
                    'placeholder' => 'Host-level — same value on all of this host\'s interfaces',
                ],
            ]);
        }

        $builder
            ->add('subnet', EntityType::class, [
                'class'        => Subnet::class,
                'choice_label' => fn($subnet) => (string) $subnet,
                'placeholder'  => '-- Select a subnet --',
                'required'     => false,
            ] + ($options['subnet_choices'] !== null
                ? ['choices' => $options['subnet_choices']]
                : ['query_builder' => fn($repo) => $repo->createQueryBuilder('s')
                        ->orderBy('s.name', 'ASC')]
            ))
            ->add('ipv4Assignment', ChoiceType::class, [
                'mapped' => false,
                'label' => 'IPv4 Assignment',
                'choices' => $ipv4Choices,
                'expanded' => true,
                'placeholder' => false,
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
                'placeholder' => false,
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => NetworkInterface::class,
            'is_edit'        => false,
            'subnet_choices' => null,
            'show_duid'      => true,
        ]);
        $resolver->setAllowedTypes('is_edit', 'bool');
        $resolver->setAllowedTypes('subnet_choices', ['null', 'array']);
        $resolver->setAllowedTypes('show_duid', 'bool');
    }
}

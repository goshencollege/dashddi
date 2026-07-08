<?php

namespace App\Form;

use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Entity\VirtualIp;
use App\Enum\VirtualIpProtocol;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VirtualIpType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];
        $subnet = $options['subnet'];

        $ipv4Choices = $isEdit
            ? ['Keep current assignment' => 'keep', 'Remove IPv4 address' => 'none', 'Specify IP' => 'select', 'Auto-assign next available' => 'auto']
            : ['No IPv4 address' => 'none', 'Specify IP' => 'select', 'Auto-assign next available' => 'auto'];

        $ipv6Choices = $isEdit
            ? ['Keep current assignment' => 'keep', 'Remove IPv6 address' => 'none', 'Specify IP' => 'select', 'Auto-assign (last IPv4 octet)' => 'auto_v4']
            : ['No IPv6 address' => 'none', 'Specify IP' => 'select', 'Auto-assign (last IPv4 octet)' => 'auto_v4'];

        $builder
            ->add('label', TextType::class, [
                'label' => 'Label',
                'attr'  => ['placeholder' => 'e.g. core-switch-gateway, cluster-vip'],
            ])
            ->add('protocol', EnumType::class, [
                'class'        => VirtualIpProtocol::class,
                'choice_label' => fn(VirtualIpProtocol $p) => $p->label(),
                'label'        => 'Protocol',
            ])
            ->add('vrid', IntegerType::class, [
                'label'    => 'VRID / Group ID',
                'required' => false,
                'attr'     => ['placeholder' => '1–255', 'min' => 1, 'max' => 255],
            ])
            ->add('notes', TextareaType::class, [
                'label'    => 'Notes',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('memberInterfaces', EntityType::class, [
                'class'        => NetworkInterface::class,
                'choice_label' => function (NetworkInterface $iface): string {
                    $label = $iface->getMacAddress();
                    if ($iface->getName()) {
                        $label .= ' (' . $iface->getName() . ')';
                    }
                    if ($iface->getHost()) {
                        $label .= ' – ' . $iface->getHost()->getName();
                    }
                    return $label;
                },
                'multiple'     => true,
                'expanded'     => false,
                'required'     => false,
                'label'        => 'Member Interfaces',
                'by_reference' => false,
                'attr'         => ['class' => 'form-select', 'size' => 6],
            ] + ($subnet !== null
                ? ['query_builder' => fn($repo) => $repo->createQueryBuilder('i')
                        ->where('i.subnet = :subnet')
                        ->andWhere('i.deletedAt IS NULL')
                        ->setParameter('subnet', $subnet)
                        ->orderBy('i.macAddress', 'ASC')]
                : ['query_builder' => fn($repo) => $repo->createQueryBuilder('i')
                        ->where('i.deletedAt IS NULL')
                        ->orderBy('i.macAddress', 'ASC')]
            ))
            ->add('ipv4Assignment', ChoiceType::class, [
                'mapped'      => false,
                'label'       => 'IPv4 Assignment',
                'choices'     => $ipv4Choices,
                'expanded'    => true,
                'placeholder' => false,
                'data'        => $isEdit ? 'keep' : 'none',
                'required'    => false,
            ])
            ->add('ipv4AddressInput', TextType::class, [
                'mapped'    => false,
                'label'     => 'IPv4 Address',
                'required'  => false,
                'attr'      => [
                    'id'           => 'ipv4_address_input',
                    'list'         => 'available-ipv4-list',
                    'placeholder'  => 'Select or type an IPv4 address',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('ipv6Assignment', ChoiceType::class, [
                'mapped'      => false,
                'label'       => 'IPv6 Assignment',
                'choices'     => $ipv6Choices,
                'expanded'    => true,
                'placeholder' => false,
                'data'        => $isEdit ? 'keep' : 'none',
                'required'    => false,
            ])
            ->add('ipv6AddressInput', TextType::class, [
                'mapped'    => false,
                'label'     => 'IPv6 Address',
                'required'  => false,
                'attr'      => [
                    'id'           => 'ipv6_address_input',
                    'list'         => 'available-ipv6-list',
                    'placeholder'  => 'Select or type an IPv6 address',
                    'autocomplete' => 'off',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VirtualIp::class,
            'is_edit'    => false,
            'subnet'     => null,
        ]);
        $resolver->setAllowedTypes('is_edit', 'bool');
        $resolver->setAllowedTypes('subnet', ['null', Subnet::class]);
    }
}

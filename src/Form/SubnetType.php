<?php

namespace App\Form;

use App\Entity\AddressBlock;
use App\Entity\Subnet;
use App\Enum\BlockType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SubnetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'e.g. Office LAN'],
            ])
            ->add('ipv4Cidr', TextType::class, [
                'required' => false,
                'label' => 'IPv4 CIDR',
                'attr' => ['placeholder' => '192.168.1.0/24'],
            ])
            ->add('ipv6Cidr', TextType::class, [
                'required' => false,
                'label' => 'IPv6 CIDR',
                'attr' => ['placeholder' => '2001:db8::/64'],
            ])
            ->add('gateway', TextType::class, [
                'required' => false,
                'attr' => ['placeholder' => '192.168.1.1'],
            ])
            ->add('vlan', IntegerType::class, [
                'required' => false,
                'label' => 'VLAN ID',
                'attr' => ['placeholder' => '100', 'min' => 1, 'max' => 4094],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'attr' => ['rows' => 3],
            ]);

        if ($options['embed_blocks']) {
            $reserved = new AddressBlock();
            $reserved->setType(BlockType::Reserved);
            $fixed = new AddressBlock();
            $fixed->setType(BlockType::Fixed);

            $builder
                ->add('reservedBlock', EmbeddedBlockType::class, [
                    'mapped' => false,
                    'label'  => false,
                    'data'   => $reserved,
                ])
                ->add('fixedBlock', EmbeddedBlockType::class, [
                    'mapped' => false,
                    'label'  => false,
                    'data'   => $fixed,
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'   => Subnet::class,
            'embed_blocks' => false,
        ]);
        $resolver->setAllowedTypes('embed_blocks', 'bool');
    }
}

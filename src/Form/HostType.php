<?php

namespace App\Form;

use App\Entity\Building;
use App\Entity\Host;
use App\Entity\NetworkInterface;
use App\Entity\Tag;
use App\Repository\TagRepository;
use App\Service\ReservedTagPrefixService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class HostType extends AbstractType
{
    public function __construct(private readonly ReservedTagPrefixService $reservedPrefixes) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'e.g. web-server-01'],
            ])
            ->add('building', EntityType::class, [
                'class'        => Building::class,
                'choice_label' => 'name',
                'placeholder'  => '-- Select a building --',
                'required'     => false,
                'label'        => 'Building',
            ])
            ->add('room', TextType::class, [
                'required' => false,
                'label'    => 'Room',
                'attr'     => ['placeholder' => 'e.g. 024'],
            ])
            ->add('tags', EntityType::class, [
                'class'         => Tag::class,
                'choice_label'  => 'name',
                'multiple'      => true,
                'expanded'      => false,
                'required'      => false,
                'label'         => 'Tags',
                'by_reference'  => false,
                'query_builder' => fn(TagRepository $r) => $this->reservedPrefixes->excludeFromQuery(
                    $r->createQueryBuilder('t')->orderBy('t.name', 'ASC'), 't'
                ),
            ]);

        if ($options['embed_interface']) {
            $builder->add('interface', NetworkInterfaceType::class, [
                'mapped'         => false,
                'label'          => false,
                'data'           => new NetworkInterface(),
                'subnet_choices' => $options['subnet_choices'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => Host::class,
            'embed_interface' => false,
            'subnet_choices'  => null,
        ]);
        $resolver->setAllowedTypes('embed_interface', 'bool');
        $resolver->setAllowedTypes('subnet_choices', ['null', 'array']);
    }
}

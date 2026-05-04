<?php

namespace App\Form;

use App\Entity\DnsView;
use App\Entity\Domain;
use App\Entity\InterfaceName;
use App\Entity\NetworkInterface;
use App\Repository\DnsViewRepository;
use App\Service\DnsViewResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InterfaceNameType extends AbstractType
{
    public function __construct(
        private readonly DnsViewRepository $viewRepo,
        private readonly DnsViewResolver $viewResolver,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var NetworkInterface|null $interface */
        $interface = $options['network_interface'];
        $subnet    = $interface?->getSubnet();

        $builder
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'e.g. web-server-01'],
            ])
            ->add('domain', EntityType::class, [
                'class'        => Domain::class,
                'choice_label' => 'name',
                'placeholder'  => '-- Select a domain --',
                'required'     => false,
                'label'        => 'Domain',
                'choice_attr'  => function (Domain $domain) use ($subnet) {
                    if ($this->viewResolver->isDomainUsable($domain, $subnet)) {
                        return [];
                    }
                    return [
                        'disabled' => 'disabled',
                        'title'    => $this->viewResolver->unusableDomainReason($domain, $subnet),
                    ];
                },
            ])
            ->add('ttl', IntegerType::class, [
                'required' => false,
                'label'    => 'TTL (seconds)',
                'attr'     => ['placeholder' => 'e.g. 3600'],
            ])
            ->add('views', EntityType::class, [
                'class'        => DnsView::class,
                'choices'      => $this->viewRepo->findBy([], ['name' => 'ASC']),
                'choice_label' => 'name',
                'multiple'     => true,
                'expanded'     => true,
                'required'     => false,
                'label'        => 'Views',
                'by_reference' => false,
            ])
            ->add('isCanonical', CheckboxType::class, [
                'label'    => 'Set as canonical (reverse DNS) name',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'        => InterfaceName::class,
            'network_interface' => null,
        ]);
        $resolver->setAllowedTypes('network_interface', ['null', NetworkInterface::class]);
    }
}

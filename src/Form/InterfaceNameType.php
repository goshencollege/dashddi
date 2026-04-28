<?php

namespace App\Form;

use App\Entity\DnsView;
use App\Entity\Domain;
use App\Entity\InterfaceName;
use App\Repository\DomainRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InterfaceNameType extends AbstractType
{
    public function __construct(private readonly DomainRepository $domainRepo) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
            ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $name   = $event->getData();
            $domain = ($name instanceof InterfaceName) ? $name->getDomain() : null;
            $this->addViewsField($event->getForm(), $domain);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data     = $event->getData();
            $domainId = $data['domain'] ?? null;
            $domain   = $domainId ? $this->domainRepo->find((int) $domainId) : null;
            $this->addViewsField($event->getForm(), $domain);
        });
    }

    private function addViewsField(FormInterface $form, ?Domain $domain): void
    {
        $choices = $domain ? $domain->getViews()->toArray() : [];
        $form->add('views', EntityType::class, [
            'class'        => DnsView::class,
            'choices'      => $choices,
            'choice_label' => 'name',
            'multiple'     => true,
            'expanded'     => true,
            'required'     => false,
            'label'        => 'Views',
            'by_reference' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InterfaceName::class]);
    }
}

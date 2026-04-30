<?php

namespace App\Form;

use App\Entity\DnsServer;
use App\Entity\DnsView;
use App\Repository\DnsViewRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DnsServerType extends AbstractType
{
    public function __construct(private readonly DnsViewRepository $viewRepo) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'e.g. ns1-internal'],
            ])
            ->add('hostname', TextType::class, [
                'label' => 'Hostname / IP',
                'attr'  => ['placeholder' => 'e.g. 192.168.1.5'],
            ])
            ->add('sshUser', TextType::class, [
                'label' => 'SSH User',
            ])
            ->add('remoteZonePath', TextType::class, [
                'label' => 'Remote Zone Path',
                'attr'  => ['placeholder' => 'e.g. /etc/bind/zones'],
            ])
            ->add('keyDirectory', TextType::class, [
                'label'    => 'DNSSEC Key Directory',
                'required' => false,
                'attr'     => ['placeholder' => 'e.g. /etc/bind/keys'],
            ])
            ->add('views', EntityType::class, [
                'class'        => DnsView::class,
                'choices'      => $this->viewRepo->findBy([], ['name' => 'ASC']),
                'choice_label' => 'name',
                'multiple'     => true,
                'expanded'     => true,
                'required'     => false,
                'label'        => 'DNS Views to deploy',
                'by_reference' => false,
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'attr'     => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DnsServer::class]);
    }
}

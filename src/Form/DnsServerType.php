<?php

namespace App\Form;

use App\Entity\DnsServer;
use App\Entity\DnsView;
use App\Enum\TsigAlgorithm;
use App\Repository\DnsServerRepository;
use App\Repository\DnsViewRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DnsServerType extends AbstractType
{
    public function __construct(
        private readonly DnsViewRepository $viewRepo,
        private readonly DnsServerRepository $serverRepo,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $primaryServers = $this->serverRepo->findBy(['serverType' => 'primary'], ['name' => 'ASC']);
        $primaryChoices = [];
        foreach ($primaryServers as $s) {
            $primaryChoices[$s->getName() . ' (' . $s->getHostname() . ')'] = $s->getHostname();
        }

        $builder
            ->add('serverType', ChoiceType::class, [
                'label'   => 'Server Type',
                'choices' => ['Primary (authoritative)' => 'primary', 'Secondary (replicates from primary)' => 'secondary'],
            ])
            ->add('primaryHostname', ChoiceType::class, [
                'label'       => 'Primary Server',
                'required'    => false,
                'choices'     => $primaryChoices,
                'placeholder' => empty($primaryChoices) ? '— No primary servers configured —' : false,
                'attr'        => ['class' => 'form-select'],
            ])
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'e.g. ns1-internal'],
            ])
            ->add('hostname', TextType::class, [
                'label' => 'IP Address',
                'help'  => 'Must be an IPv4 or IPv6 address. Used for both SSH access and BIND zone transfer configuration.',
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
            ->add('bindUser', TextType::class, [
                'label'      => 'BIND Service User',
                'attr'       => ['placeholder' => 'bind'],
                'empty_data' => 'bind',
                'help'       => 'OS user that runs BIND. Key directories are chowned to this user after creation.',
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
            ])
            ->add('ddnsAlgorithm', EnumType::class, [
                'class'        => TsigAlgorithm::class,
                'choice_label' => fn(TsigAlgorithm $a) => $a->label(),
                'placeholder'  => '— Disabled —',
                'required'     => false,
                'label'        => 'DDNS Algorithm',
                'help'         => 'Selecting an algorithm enables DDNS and generates a TSIG key for this server. The key is written into the generated BIND and Kea D2 configs automatically.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DnsServer::class]);
    }
}

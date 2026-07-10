<?php

namespace App\Form;

use App\Entity\DnsView;
use App\Entity\Domain;
use App\Entity\DomainRecord;
use App\Entity\NetworkInterface;
use App\Entity\Subnet;
use App\Entity\VirtualIp;
use App\Enum\RecordType;
use App\Validator\TxtRecordValueValidator;
use App\Repository\DnsViewRepository;
use App\Repository\DomainRepository;
use App\Service\DnsViewResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DomainRecordType extends AbstractType
{
    public function __construct(
        private readonly DnsViewRepository $viewRepo,
        private readonly DomainRepository  $domainRepo,
        private readonly DnsViewResolver   $viewResolver,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var NetworkInterface|null $interface */
        $interface = $options['network_interface'];
        /** @var VirtualIp|null $virtualIp */
        $virtualIp = $options['virtual_ip'];

        $subnet = $interface?->getSubnet() ?? $virtualIp?->getSubnet();
        $hasContext = $interface !== null || $virtualIp !== null;

        $builder
            ->add('hostname', TextType::class, [
                'label' => 'Hostname',
                'attr'  => ['placeholder' => 'e.g. mail  or  @  or  *.dev'],
            ])
            ->add('type', EnumType::class, [
                'class'        => RecordType::class,
                'choice_label' => fn(RecordType $t) => $t->value,
            ])
            ->add('value', TextType::class, [
                'label'      => 'Value',
                'required'   => false,
                'empty_data' => '',
                'attr'       => ['placeholder' => 'e.g. 192.168.1.1  or  mail.example.com.'],
            ])
            ->add('ttl', IntegerType::class, [
                'required' => false,
                'label'    => 'TTL (seconds)',
                'attr'     => ['placeholder' => 'e.g. 3600'],
            ])
            ->add('comment', TextareaType::class, [
                'required' => false,
                'label'    => 'Comment',
            ]);

        // When creating/editing from an interface or virtual IP context, add domain, isCanonical,
        // and (for new records only) override the type field with a ChoiceType that includes A+AAAA.
        if ($hasContext) {
            if ($options['allow_dual_stack']) {
                $typeChoices = ['A+AAAA' => 'A+AAAA'];
                foreach (RecordType::cases() as $case) {
                    $typeChoices[$case->value] = $case;
                }

                $builder->add('type', ChoiceType::class, [
                    'choices'      => $typeChoices,
                    'choice_value' => fn($c) => $c instanceof RecordType ? $c->value : (string) $c,
                ]);

                $builder->add('create_aaaa_companion', HiddenType::class, [
                    'mapped'   => false,
                    'required' => false,
                    'data'     => '',
                ]);
            }

            $builder->add('domain', EntityType::class, [
                'class'         => Domain::class,
                'choice_label'  => 'name',
                'placeholder'   => '-- Select a domain --',
                'required'      => false,
                'label'         => 'Domain',
                'query_builder' => fn($repo) => $repo->createQueryBuilder('d')
                    ->where('d.excludeFromInterfaces = false')
                    ->orderBy('d.name', 'ASC'),
                'choice_attr'   => function (Domain $domain) use ($subnet) {
                    if ($this->viewResolver->isDomainUsable($domain, $subnet)) {
                        return [];
                    }
                    return [
                        'disabled' => 'disabled',
                        'title'    => $this->viewResolver->unusableDomainReason($domain, $subnet),
                    ];
                },
            ]);
            $builder->add('isCanonical', CheckboxType::class, [
                'label'    => 'Set as canonical (reverse DNS) name',
                'required' => false,
            ]);
        }

        $domainFromPreSetData = null;

        if ($options['allow_dual_stack']) {
            $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {
                $record = $event->getData();
                if ($record instanceof DomainRecord && $record->getId() === null) {
                    $event->getForm()->get('type')->setData('A+AAAA');
                }
            });
        }

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($hasContext, $subnet, &$domainFromPreSetData) {
            $record = $event->getData();
            $domain = ($record instanceof DomainRecord) ? $record->getDomain() : null;
            $domainFromPreSetData = $domain;
            $this->addViewsField($event->getForm(), $domain, $hasContext ? $subnet : null);
        });

        // Rebuild views choices before validation so submitted view IDs remain valid.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($hasContext, $subnet, $options, &$domainFromPreSetData) {
            $data = $event->getData();

            // Intercept A+AAAA meta option: treat this submission as a plain A record and flag
            // the companion AAAA record for creation in the controller after validation.
            if ($hasContext && $options['allow_dual_stack'] && ($data['type'] ?? '') === 'A+AAAA') {
                $data['type'] = RecordType::A->value;
                $data['create_aaaa_companion'] = '1';
            }

            if (($data['type'] ?? '') === 'TXT' && isset($data['value'])) {
                $data['value'] = TxtRecordValueValidator::normalizeTxtValue($data['value']);
            }

            $event->setData($data);

            if ($hasContext) {
                $domainId = isset($data['domain']) ? (int) $data['domain'] : null;
                $domain   = $domainId ? $this->domainRepo->find($domainId) : null;
            } else {
                $domain = $domainFromPreSetData;
            }
            $this->addViewsField($event->getForm(), $domain, $hasContext ? $subnet : null);
        });
    }

    private function addViewsField(FormInterface $form, ?Domain $domain, ?Subnet $subnet): void
    {
        if ($subnet !== null) {
            $choices = $domain ? $this->viewResolver->availableViewsFor($domain, $subnet) : [];
        } else {
            $choices = $domain ? $domain->getViews()->toArray() : [];
        }

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
        $resolver->setDefaults([
            'data_class'        => DomainRecord::class,
            'network_interface' => null,
            'virtual_ip'        => null,
            'allow_dual_stack'  => false,
        ]);
        $resolver->setAllowedTypes('network_interface', ['null', NetworkInterface::class]);
        $resolver->setAllowedTypes('virtual_ip', ['null', VirtualIp::class]);
        $resolver->setAllowedTypes('allow_dual_stack', 'bool');
    }
}

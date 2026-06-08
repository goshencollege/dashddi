<?php

namespace App\Form;

use App\Entity\DnsView;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DnsViewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $aclEntry = [
            'entry_type'    => TextType::class,
            'entry_options' => ['label' => false],
            'allow_add'     => true,
            'allow_delete'  => true,
            'required'      => false,
            'label'         => false,
        ];

        $builder
            ->add('name', TextType::class, [
                'label' => 'View Name',
                'attr'  => ['placeholder' => 'e.g. internal'],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('matchClients',   CollectionType::class, $aclEntry)
            ->add('allowQuery',     CollectionType::class, $aclEntry)
            ->add('allowTransfer',  CollectionType::class, $aclEntry)
            ->add('alsoNotify',     CollectionType::class, $aclEntry)
            ->add('extraOptions', TextareaType::class, [
                'label'    => 'Additional Options',
                'required' => false,
                'attr'     => [
                    'rows'        => 5,
                    'placeholder' => "recursion yes;\nforwarders { 8.8.8.8; };",
                    'class'       => 'form-control font-monospace',
                ],
                'help' => 'Raw BIND statements inserted verbatim inside the view block.',
            ])
;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DnsView::class]);
    }
}

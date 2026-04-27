<?php

namespace App\Form;

use App\Entity\DomainRecord;
use App\Enum\RecordType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DomainRecordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('hostname', TextType::class, [
                'label' => 'Hostname',
                'attr'  => ['placeholder' => 'e.g. mail  or  @  or  *.dev'],
            ])
            ->add('type', EnumType::class, [
                'class' => RecordType::class,
                'choice_label' => fn(RecordType $t) => $t->value,
            ])
            ->add('value', TextType::class, [
                'label' => 'Value',
                'attr'  => ['placeholder' => 'e.g. 192.168.1.1  or  mail.example.com.'],
            ])
            ->add('ttl', IntegerType::class, [
                'required' => false,
                'label'    => 'TTL (seconds)',
                'attr'     => ['placeholder' => 'e.g. 3600'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DomainRecord::class]);
    }
}

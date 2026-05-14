<?php

namespace App\Form;

use App\Entity\RadiusServer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RadiusServerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'attr' => ['placeholder' => 'e.g. Primary RADIUS'],
            ])
            ->add('hostname', TextType::class, [
                'label' => 'Hostname / IP',
                'attr'  => ['placeholder' => 'e.g. radius1.example.com or 10.0.0.5'],
            ])
            ->add('sshUser', TextType::class, [
                'label' => 'SSH User',
                'attr'  => ['placeholder' => 'root'],
            ])
            ->add('remotePath', TextType::class, [
                'label' => 'Remote Path',
                'attr'  => ['placeholder' => '/etc/freeradius'],
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'attr'     => ['rows' => 2, 'placeholder' => 'Optional notes'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RadiusServer::class]);
    }
}

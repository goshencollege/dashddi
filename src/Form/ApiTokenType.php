<?php

namespace App\Form;

use App\Entity\ApiToken;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\RouterInterface;

class ApiTokenType extends AbstractType
{
    private const EXCLUDED_ROUTES = [];

    public function __construct(private RouterInterface $router) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Token Name',
                'attr'  => ['placeholder' => 'e.g. Monitoring Script, Network Inventory'],
            ])
            ->add('allowedRoutes', ChoiceType::class, [
                'label'    => 'Allowed Endpoints',
                'choices'  => $this->buildRouteChoices(),
                'multiple' => true,
                'expanded' => true,
            ])
            ->add('allowedCidrs', TextareaType::class, [
                'label'    => 'Allowed IP / CIDR Ranges',
                'required' => false,
                'attr'     => ['rows' => 4, 'placeholder' => "192.168.1.0/24\n10.0.0.5"],
                'help'     => 'One IP address or CIDR range per line. Leave blank to allow requests from any IP.',
            ])
            ->add('expiresAt', DateType::class, [
                'label'    => 'Expiry Date',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'help'     => 'Leave blank for no expiry.',
            ]);

        $builder->get('allowedCidrs')->addModelTransformer(new CallbackTransformer(
            fn(array $cidrs) => implode("\n", $cidrs),
            fn(string $text) => array_values(array_filter(array_map('trim', explode("\n", $text)))),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ApiToken::class]);
    }

    private function buildRouteChoices(): array
    {
        $choices = [];

        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/')) {
                continue;
            }
            if (in_array($name, self::EXCLUDED_ROUTES, true)) {
                continue;
            }
            $methods = $route->getMethods() ?: ['ANY'];
            $label   = implode('|', $methods) . ' ' . $route->getPath();
            $choices[$label] = $name;
        }

        ksort($choices);
        return $choices;
    }
}

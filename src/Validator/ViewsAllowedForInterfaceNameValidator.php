<?php

namespace App\Validator;

use App\Entity\InterfaceName;
use App\Service\DnsViewResolver;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ViewsAllowedForInterfaceNameValidator extends ConstraintValidator
{
    public function __construct(private readonly DnsViewResolver $resolver) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ViewsAllowedForInterfaceName) {
            throw new UnexpectedTypeException($constraint, ViewsAllowedForInterfaceName::class);
        }

        if (!$value instanceof InterfaceName) {
            return;
        }

        $domain = $value->getDomain();
        $subnet = $value->getNetworkInterface()?->getSubnet();
        $allowed    = $this->resolver->availableViewsFor($domain, $subnet);
        $allowedIds = array_map(fn($v) => $v->getId(), $allowed);

        foreach ($value->getViews() as $view) {
            if (!in_array($view->getId(), $allowedIds, true)) {
                $this->context->buildViolation($constraint->message)
                    ->setParameter('{{ view }}', $view->getName())
                    ->addViolation();
                return;
            }
        }
    }
}

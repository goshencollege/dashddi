<?php

namespace App\Validator;

use App\Entity\DomainRecord;
use App\Service\DnsViewResolver;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ViewsAllowedForDomainRecordValidator extends ConstraintValidator
{
    public function __construct(private readonly DnsViewResolver $resolver) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ViewsAllowedForDomainRecord) {
            throw new UnexpectedTypeException($constraint, ViewsAllowedForDomainRecord::class);
        }

        if (!$value instanceof DomainRecord) {
            return;
        }

        // Only validate view restrictions when an interface is linked (subnet constraint applies)
        if ($value->getNetworkInterface() === null) {
            return;
        }

        $domain     = $value->getDomain();
        $subnet     = $value->getNetworkInterface()?->getSubnet();
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

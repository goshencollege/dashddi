<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class ViewsAllowedForDomainRecord extends Constraint
{
    public string $message = 'View "{{ view }}" is not allowed for this domain and subnet combination.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}

<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class NoMultipleSpfTxt extends Constraint
{
    public string $message = 'A domain may only have one SPF TXT record at "{{ hostname }}". Another SPF record already exists.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}

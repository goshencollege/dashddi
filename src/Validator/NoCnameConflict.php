<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class NoCnameConflict extends Constraint
{
    public string $cnameAtApexMessage = 'CNAME records cannot be placed at the zone apex (@).';
    public string $cnameConflictMessage = 'A CNAME record cannot share a name with any other record type at "{{ hostname }}".';
    public string $otherConflictMessage = 'Cannot add a {{ type }} record: a CNAME already exists at "{{ hostname }}".';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}

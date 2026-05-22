<?php

namespace App\Service;

use Doctrine\ORM\QueryBuilder;

class ReservedTagPrefixService
{
    /** @param string[] $prefixes */
    public function __construct(private readonly array $prefixes) {}

    /**
     * Returns the first matching reserved prefix if $name starts with one, null otherwise.
     * Comparison is case-insensitive.
     */
    public function matchingPrefix(string $name): ?string
    {
        foreach ($this->prefixes as $prefix) {
            if (stripos($name, $prefix) === 0) {
                return $prefix;
            }
        }
        return null;
    }

    /**
     * Appends NOT LIKE conditions to $qb so that tags whose names start
     * with any reserved prefix are excluded. $alias is the DQL alias for the Tag entity.
     */
    public function excludeFromQuery(QueryBuilder $qb, string $alias): QueryBuilder
    {
        foreach ($this->prefixes as $i => $prefix) {
            $qb->andWhere("$alias.name NOT LIKE :rp$i")
               ->setParameter("rp$i", $prefix . '%');
        }
        return $qb;
    }

    /** @return string[] */
    public function getPrefixes(): array { return $this->prefixes; }
}

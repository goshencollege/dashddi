<?php

namespace App\Service;

use App\Entity\DnsView;
use App\Entity\Domain;
use App\Entity\Subnet;

class DnsViewResolver
{
    /**
     * Returns views that are valid for the given domain+subnet combination.
     * If the subnet has no views configured, only the domain constraint applies.
     */
    public function availableViewsFor(?Domain $domain, ?Subnet $subnet): array
    {
        if ($domain === null) {
            return [];
        }

        $domainViews = $domain->getViews()->toArray();

        if ($subnet === null || $subnet->getViews()->isEmpty()) {
            return $domainViews;
        }

        $subnetViewIds = array_map(fn(DnsView $v) => $v->getId(), $subnet->getViews()->toArray());

        return array_values(array_filter(
            $domainViews,
            fn(DnsView $v) => in_array($v->getId(), $subnetViewIds, true)
        ));
    }

    public function isDomainUsable(Domain $domain, ?Subnet $subnet): bool
    {
        if ($domain->getViews()->isEmpty()) {
            return true;
        }
        if ($subnet === null || $subnet->getViews()->isEmpty()) {
            return true;
        }
        return count($this->availableViewsFor($domain, $subnet)) > 0;
    }

    public function unusableDomainReason(Domain $domain, ?Subnet $subnet): string
    {
        if ($subnet !== null && !$subnet->getViews()->isEmpty()
            && !$domain->getViews()->isEmpty()
            && count($this->availableViewsFor($domain, $subnet)) === 0
        ) {
            return 'No views in common with this subnet';
        }
        return '';
    }
}

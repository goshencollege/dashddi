<?php

namespace App\Service;

class OuiLookupService
{
    private ?array $vendors = null;

    public function __construct(private readonly string $databasePath) {}

    /**
     * Resolves the vendor for a MAC address's OUI (first 24 bits).
     * Returns null if the address is malformed or the OUI is not in the database.
     * Locally administered addresses (e.g. privacy-randomized MACs) are reported
     * as such rather than looked up, since their OUI carries no vendor meaning.
     */
    public function lookup(string $macAddress): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $macAddress));
        if (strlen($hex) < 6 || preg_match('/^0+$/', $hex)) {
            return null;
        }

        $firstByte = hexdec(substr($hex, 0, 2));
        if (($firstByte & 0x02) !== 0) {
            return 'Locally administered (randomized)';
        }

        return $this->getVendors()[substr($hex, 0, 6)] ?? null;
    }

    private function getVendors(): array
    {
        if ($this->vendors === null) {
            $this->vendors = is_file($this->databasePath) ? require $this->databasePath : [];
        }
        return $this->vendors;
    }
}

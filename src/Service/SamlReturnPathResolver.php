<?php

namespace App\Service;

class SamlReturnPathResolver
{
    /**
     * RelayState/return_to is attacker-controllable (crafted link, or a tampered IdP
     * POST), so only same-origin relative paths are accepted — anything carrying a
     * scheme or host (including protocol-relative "//" and backslash tricks) is an
     * open-redirect attempt and is rejected.
     */
    public function sanitize(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return null;
        }

        return $path;
    }
}

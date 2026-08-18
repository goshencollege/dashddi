<?php

namespace App\Twig;

use App\Repository\UserPreferenceRepository;
use App\Service\OuiLookupService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly UserPreferenceRepository $prefRepo,
        private readonly OuiLookupService $ouiLookup,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_theme', $this->currentTheme(...)),
            new TwigFunction('mac_vendor', $this->ouiLookup->lookup(...)),
        ];
    }

    public function currentTheme(): string
    {
        $user = $this->security->getUser();
        if (!$user) {
            return 'purple';
        }

        $pref = $this->prefRepo->findByIdentifier($user->getUserIdentifier());
        return $pref?->getTheme() ?? 'purple';
    }
}

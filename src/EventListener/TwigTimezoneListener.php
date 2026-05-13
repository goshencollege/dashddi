<?php

namespace App\EventListener;

use App\Repository\AppSettingRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Extension\CoreExtension;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
class TwigTimezoneListener
{
    public function __construct(
        private readonly AppSettingRepository $settingRepo,
        private readonly Environment          $twig,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tz = $this->settingRepo->getInstance()->getTimezone();
        if ($tz !== null) {
            $this->twig->getExtension(CoreExtension::class)->setTimezone($tz);
        }
    }
}

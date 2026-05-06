<?php

namespace App\Controller;

use App\Form\AppSettingType;
use App\Repository\AppSettingRepository;
use App\Service\SmtpMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AppSettingController extends AbstractController
{
    #[Route('/settings', name: 'app_settings', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        AppSettingRepository $repo,
        EntityManagerInterface $em,
    ): Response {
        $setting = $repo->getInstance();
        $form    = $this->createForm(AppSettingType::class, $setting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Application settings saved.');
            return $this->redirectToRoute('app_settings');
        }

        return $this->render('app_setting/edit.html.twig', ['form' => $form]);
    }

    #[Route('/settings/test-email', name: 'app_settings_test_email', methods: ['POST'])]
    public function testEmail(
        Request $request,
        AppSettingRepository $repo,
        SmtpMailerService $mailer,
    ): Response {
        if (!$this->isCsrfTokenValid('test_email', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_settings');
        }

        $to = trim((string) $request->request->get('test_recipient', ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('danger', 'Please enter a valid recipient email address.');
            return $this->redirectToRoute('app_settings');
        }

        if (!$mailer->isConfigured()) {
            $this->addFlash('warning', 'SMTP is not fully configured. Set at least the host and from address, then save before testing.');
            return $this->redirectToRoute('app_settings');
        }

        try {
            $mailer->send($to, 'DashDDI — SMTP test email', "This is a test email from DashDDI.\n\nIf you received this, your SMTP settings are working correctly.");
            $this->addFlash('success', "Test email sent to {$to}.");
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Failed to send test email: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_settings');
    }
}

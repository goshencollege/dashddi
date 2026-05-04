<?php

namespace App\Controller;

use App\Form\AppSettingType;
use App\Repository\AppSettingRepository;
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
}

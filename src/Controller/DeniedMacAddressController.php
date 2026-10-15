<?php

namespace App\Controller;

use App\Entity\DeniedMacAddress;
use App\Form\DeniedMacAddressType;
use App\Repository\DeniedMacAddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/denied-mac-addresses')]
class DeniedMacAddressController extends AbstractController
{
    #[Route('', name: 'denied_mac_address_index', methods: ['GET'])]
    public function index(DeniedMacAddressRepository $repo): Response
    {
        return $this->render('denied_mac_address/index.html.twig', [
            'deniedMacAddresses' => $repo->findBy([], ['macAddress' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'denied_mac_address_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $deniedMacAddress = new DeniedMacAddress();
        $form = $this->createForm(DeniedMacAddressType::class, $deniedMacAddress);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($deniedMacAddress);
            $em->flush();
            $this->addFlash('success', 'MAC address "' . $deniedMacAddress->getMacAddress() . '" denied.');
            return $this->redirectToRoute('denied_mac_address_index');
        }

        return $this->render('denied_mac_address/form.html.twig', [
            'form'              => $form,
            'deniedMacAddress'  => $deniedMacAddress,
            'title'             => 'Deny MAC Address',
        ]);
    }

    #[Route('/{id}/edit', name: 'denied_mac_address_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DeniedMacAddress $deniedMacAddress, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(DeniedMacAddressType::class, $deniedMacAddress);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Denied MAC address updated.');
            return $this->redirectToRoute('denied_mac_address_index');
        }

        return $this->render('denied_mac_address/form.html.twig', [
            'form'              => $form,
            'deniedMacAddress'  => $deniedMacAddress,
            'title'             => 'Edit Denied MAC Address: ' . $deniedMacAddress->getMacAddress(),
        ]);
    }

    #[Route('/{id}/toggle', name: 'denied_mac_address_toggle', methods: ['POST'])]
    public function toggle(Request $request, DeniedMacAddress $deniedMacAddress, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('toggle_denied_mac_address_' . $deniedMacAddress->getId(), $request->request->get('_token'))) {
            $deniedMacAddress->setEnabled(!$deniedMacAddress->isEnabled());
            $em->flush();
            $this->addFlash('success', 'MAC address "' . $deniedMacAddress->getMacAddress() . '" is now ' . ($deniedMacAddress->isEnabled() ? 'denied' : 'disabled') . '.');
        }
        return $this->redirectToRoute('denied_mac_address_index');
    }

    #[Route('/{id}/delete', name: 'denied_mac_address_delete', methods: ['POST'])]
    public function delete(Request $request, DeniedMacAddress $deniedMacAddress, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_denied_mac_address_' . $deniedMacAddress->getId(), $request->request->get('_token'))) {
            $em->remove($deniedMacAddress);
            $em->flush();
            $this->addFlash('success', 'Denied MAC address removed.');
        }
        return $this->redirectToRoute('denied_mac_address_index');
    }
}

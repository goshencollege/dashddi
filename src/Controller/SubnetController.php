<?php

namespace App\Controller;

use App\Entity\Subnet;
use App\Form\SubnetType;
use App\Repository\SubnetRepository;
use App\Service\IpAddressManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/subnets')]
class SubnetController extends AbstractController
{
    #[Route('', name: 'subnet_index', methods: ['GET'])]
    public function index(SubnetRepository $repo): Response
    {
        return $this->render('subnet/index.html.twig', [
            'subnets' => $repo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'subnet_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $subnet = new Subnet();
        $form = $this->createForm(SubnetType::class, $subnet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($subnet);
            $em->flush();
            $this->addFlash('success', 'Subnet "' . $subnet->getName() . '" created.');
            return $this->redirectToRoute('subnet_show', ['id' => $subnet->getId()]);
        }

        return $this->render('subnet/form.html.twig', [
            'form'   => $form,
            'subnet' => $subnet,
            'title'  => 'New Subnet',
        ]);
    }

    #[Route('/{id}', name: 'subnet_show', methods: ['GET'])]
    public function show(Subnet $subnet, IpAddressManager $manager): Response
    {
        return $this->render('subnet/show.html.twig', [
            'subnet'          => $subnet,
            'available_ipv4'  => $subnet->getIpv4Cidr() ? $manager->getAvailableIpv4($subnet, 255) : [],
            'available_ipv6'  => $subnet->getIpv6Cidr() ? $manager->getAvailableIpv6($subnet, 20) : [],
        ]);
    }

    #[Route('/{id}/edit', name: 'subnet_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Subnet $subnet, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(SubnetType::class, $subnet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Subnet updated.');
            return $this->redirectToRoute('subnet_show', ['id' => $subnet->getId()]);
        }

        return $this->render('subnet/form.html.twig', [
            'form'   => $form,
            'subnet' => $subnet,
            'title'  => 'Edit Subnet: ' . $subnet->getName(),
        ]);
    }

    #[Route('/{id}/delete', name: 'subnet_delete', methods: ['POST'])]
    public function delete(Request $request, Subnet $subnet, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_subnet_' . $subnet->getId(), $request->request->get('_token'))) {
            $em->remove($subnet);
            $em->flush();
            $this->addFlash('success', 'Subnet deleted.');
        }
        return $this->redirectToRoute('subnet_index');
    }
}

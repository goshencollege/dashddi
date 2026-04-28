<?php

namespace App\Controller;

use App\Entity\Building;
use App\Form\BuildingType;
use App\Repository\BuildingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/buildings')]
class BuildingController extends AbstractController
{
    #[Route('', name: 'building_index', methods: ['GET'])]
    public function index(BuildingRepository $repo): Response
    {
        return $this->render('building/index.html.twig', [
            'buildings' => $repo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'building_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $building = new Building();
        $form = $this->createForm(BuildingType::class, $building);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($building);
            $em->flush();
            $this->addFlash('success', 'Building "' . $building->getName() . '" added.');
            return $this->redirectToRoute('building_index');
        }

        return $this->render('building/form.html.twig', [
            'form'     => $form,
            'building' => $building,
            'title'    => 'Add Building',
        ]);
    }

    #[Route('/{id}/edit', name: 'building_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Building $building, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(BuildingType::class, $building);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Building updated.');
            return $this->redirectToRoute('building_index');
        }

        return $this->render('building/form.html.twig', [
            'form'     => $form,
            'building' => $building,
            'title'    => 'Edit Building: ' . $building->getName(),
        ]);
    }

    #[Route('/{id}/delete', name: 'building_delete', methods: ['POST'])]
    public function delete(Request $request, Building $building, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_building_' . $building->getId(), $request->request->get('_token'))) {
            $em->remove($building);
            $em->flush();
            $this->addFlash('success', 'Building deleted.');
        }
        return $this->redirectToRoute('building_index');
    }
}

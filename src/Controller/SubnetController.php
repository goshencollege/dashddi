<?php

namespace App\Controller;

use App\Entity\AddressBlock;
use App\Entity\Subnet;
use App\Entity\UserPreference;
use App\Enum\BlockType;
use App\Form\SubnetType;
use App\Repository\SubnetRepository;
use App\Repository\UserPreferenceRepository;
use App\Repository\VrfRepository;
use App\Service\IpAddressManager;
use IPLib\Factory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/subnets')]
class SubnetController extends AbstractController
{
    #[Route('', name: 'subnet_index', methods: ['GET'])]
    public function index(Request $request, SubnetRepository $repo, VrfRepository $vrfRepo, UserPreferenceRepository $prefRepo, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $pref = $user ? $prefRepo->findByIdentifier($user->getUserIdentifier()) : null;

        if ($request->query->has('view')) {
            $view = $request->query->get('view');
            if (!in_array($view, ['name', 'ipv4', 'ipv6'], true)) {
                $view = 'name';
            }
            if ($user) {
                if (!$pref) {
                    $pref = new UserPreference($user->getUserIdentifier());
                    $em->persist($pref);
                }
                $pref->setSubnetViewMode($view);
                $em->flush();
            }
        } else {
            $view = $pref?->getSubnetViewMode() ?? 'name';
        }

        return $this->render('subnet/index.html.twig', [
            'subnets' => $view === 'name' ? $repo->findBy(['isContainer' => false], ['name' => 'ASC']) : null,
            'tree'    => $view !== 'name' ? $repo->buildFlatHierarchy($view) : null,
            'view'    => $view,
            'vrfs'    => $vrfRepo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'subnet_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $subnet = new Subnet();
        $form = $this->createForm(SubnetType::class, $subnet, ['embed_blocks' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($subnet);

            $errors = [];
            foreach (['reservedBlock' => BlockType::Reserved, 'fixedBlock' => BlockType::Fixed] as $field => $type) {
                $block = $form->get($field)->getData();
                if ($block->getStartIp() === '' || $block->getEndIp() === '') {
                    continue;
                }
                $block->setSubnet($subnet);
                $block->setType($type);
                $error = $this->validateBlock($block, $subnet);
                if ($error) {
                    $errors[] = $error;
                } else {
                    $em->persist($block);
                }
            }

            if ($errors) {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error);
                }
            } else {
                $em->flush();
                $this->addFlash('success', 'Subnet "' . $subnet->getName() . '" created.');
                return $this->redirectToRoute('subnet_show', ['id' => $subnet->getId()]);
            }
        }

        return $this->render('subnet/form.html.twig', [
            'form'         => $form,
            'subnet'       => $subnet,
            'title'        => 'New Subnet',
            'embed_blocks' => true,
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
            'form'         => $form,
            'subnet'       => $subnet,
            'title'        => 'Edit Subnet: ' . $subnet->getName(),
            'embed_blocks' => false,
        ]);
    }

    private function validateBlock(AddressBlock $block, Subnet $subnet): ?string
    {
        $start = Factory::parseAddressString($block->getStartIp());
        $end   = Factory::parseAddressString($block->getEndIp());

        if (!$start) return 'Start IP is not a valid IP address.';
        if (!$end)   return 'End IP is not a valid IP address.';

        $version = $start->getAddressType();
        if ($version !== $end->getAddressType()) {
            return 'Start and End IP must be the same protocol.';
        }

        $cidr = $version === 4 ? $subnet->getIpv4Cidr() : $subnet->getIpv6Cidr();
        if (!$cidr) {
            return sprintf('This subnet has no IPv%d CIDR defined.', $version);
        }

        $range = Factory::parseRangeString($cidr);
        if (!$range->contains($start) || !$range->contains($end)) {
            return sprintf('Block IPs must fall within %s.', $cidr);
        }

        if ($start->getComparableString() > $end->getComparableString()) {
            return 'Start IP must be less than or equal to End IP.';
        }

        return null;
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

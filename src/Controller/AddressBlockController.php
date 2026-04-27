<?php

namespace App\Controller;

use App\Entity\AddressBlock;
use App\Entity\Subnet;
use App\Form\AddressBlockType;
use Doctrine\ORM\EntityManagerInterface;
use IPLib\Factory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AddressBlockController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/subnet/{subnetId}/blocks/new', name: 'block_new')]
    public function new(int $subnetId, Request $request): Response
    {
        $subnet = $this->em->find(Subnet::class, $subnetId);
        if (!$subnet) {
            throw $this->createNotFoundException();
        }

        $block = new AddressBlock();
        $block->setSubnet($subnet);

        $form = $this->createForm(AddressBlockType::class, $block);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $error = $this->validateBlock($block, $subnet);
            if ($error) {
                $this->addFlash('danger', $error);
            } else {
                $this->em->persist($block);
                $this->em->flush();
                $this->addFlash('success', 'Block added.');
                return $this->redirectToRoute('subnet_show', ['id' => $subnetId]);
            }
        }

        return $this->render('address_block/form.html.twig', [
            'form'   => $form,
            'subnet' => $subnet,
            'block'  => $block,
        ]);
    }

    #[Route('/blocks/{id}/edit', name: 'block_edit')]
    public function edit(AddressBlock $block, Request $request): Response
    {
        $subnet = $block->getSubnet();
        $form   = $this->createForm(AddressBlockType::class, $block);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $error = $this->validateBlock($block, $subnet);
            if ($error) {
                $this->addFlash('danger', $error);
            } else {
                $this->em->flush();
                $this->addFlash('success', 'Block updated.');
                return $this->redirectToRoute('subnet_show', ['id' => $subnet->getId()]);
            }
        }

        return $this->render('address_block/form.html.twig', [
            'form'   => $form,
            'subnet' => $subnet,
            'block'  => $block,
        ]);
    }

    #[Route('/blocks/{id}/delete', name: 'block_delete', methods: ['POST'])]
    public function delete(AddressBlock $block, Request $request): Response
    {
        $subnetId = $block->getSubnet()->getId();

        if ($this->isCsrfTokenValid('delete_block_' . $block->getId(), $request->request->get('_token'))) {
            $this->em->remove($block);
            $this->em->flush();
            $this->addFlash('success', 'Block deleted.');
        }

        return $this->redirectToRoute('subnet_show', ['id' => $subnetId]);
    }

    private function validateBlock(AddressBlock $block, Subnet $subnet): ?string
    {
        $start = Factory::parseAddressString($block->getStartIp());
        $end   = Factory::parseAddressString($block->getEndIp());

        if (!$start) return 'Start IP is not a valid IP address.';
        if (!$end)   return 'End IP is not a valid IP address.';

        $startVersion = $start->getAddressType()->getVersion();
        $endVersion   = $end->getAddressType()->getVersion();

        if ($startVersion !== $endVersion) {
            return 'Start and End IP must be the same protocol (both IPv4 or both IPv6).';
        }

        // Verify the range falls within the subnet CIDR
        $cidr = ($startVersion === 4) ? $subnet->getIpv4Cidr() : $subnet->getIpv6Cidr();
        if (!$cidr) {
            return sprintf('This subnet has no IPv%d CIDR defined.', $startVersion);
        }

        $subnetRange = Factory::parseRangeString($cidr);
        if (!$subnetRange->contains($start) || !$subnetRange->contains($end)) {
            return sprintf('Start and End IP must both fall within %s.', $cidr);
        }

        // Compare as comparable addresses
        $startCmp = $start->getComparableString();
        $endCmp   = $end->getComparableString();

        if ($startCmp > $endCmp) {
            return 'Start IP must be less than or equal to End IP.';
        }

        return null;
    }
}

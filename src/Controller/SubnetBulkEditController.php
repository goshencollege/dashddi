<?php

namespace App\Controller;

use App\Form\SubnetBulkEditType;
use App\Repository\SubnetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/subnets')]
class SubnetBulkEditController extends AbstractController
{
    #[Route('/bulk-edit', name: 'subnet_bulk_edit', methods: ['GET', 'POST'])]
    public function bulkEdit(Request $request, SubnetRepository $repo, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            $raw = $request->request->all();
            $ids = array_map('intval', (array) ($raw['subnet_ids'] ?? []));
        } else {
            $ids = array_map('intval', (array) ($request->query->all()['ids'] ?? []));
        }

        $ids = array_values(array_filter($ids));

        if (empty($ids)) {
            $this->addFlash('warning', 'No subnets selected for bulk edit.');
            return $this->redirectToRoute('subnet_index');
        }

        $subnets = $repo->findBy(['id' => $ids]);

        if (empty($subnets)) {
            $this->addFlash('warning', 'No matching subnets found.');
            return $this->redirectToRoute('subnet_index');
        }

        $form = $this->createForm(SubnetBulkEditType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $post = $request->request->all();

            $applyVlan          = !empty($post['apply_vlan']);
            $applyDescription   = !empty($post['apply_description']);
            $applyTags          = !empty($post['apply_tags']);
            $applySoaNameserver = !empty($post['apply_soaNameserver']);
            $applySoaEmail      = !empty($post['apply_soaEmail']);
            $applySoaRefresh    = !empty($post['apply_soaRefresh']);
            $applySoaRetry      = !empty($post['apply_soaRetry']);
            $applySoaExpire     = !empty($post['apply_soaExpire']);
            $applySoaTtl        = !empty($post['apply_soaTtl']);
            $applyViews         = !empty($post['apply_views']);
            $applyDnssec        = !empty($post['apply_dnssecPolicy']);
            $applyRetention     = !empty($post['apply_leaseRetentionDays']);

            $tagsMode  = $post['tags_mode']  ?? 'replace';
            $viewsMode = $post['views_mode'] ?? 'replace';

            foreach ($subnets as $subnet) {
                if ($applyVlan) {
                    $subnet->setVlan($data['vlan']);
                }
                if ($applyDescription) {
                    $subnet->setDescription($data['description'] ?: null);
                }
                if ($applyTags) {
                    $newTags = $data['tags'] ?? [];
                    if ($tagsMode === 'replace') {
                        foreach ($subnet->getTags()->toArray() as $tag) {
                            $subnet->removeTag($tag);
                        }
                        foreach ($newTags as $tag) {
                            $subnet->addTag($tag);
                        }
                    } elseif ($tagsMode === 'add') {
                        foreach ($newTags as $tag) {
                            $subnet->addTag($tag);
                        }
                    } else {
                        foreach ($newTags as $tag) {
                            $subnet->removeTag($tag);
                        }
                    }
                }
                if ($applySoaNameserver) {
                    $subnet->setSoaNameserver($data['soaNameserver'] ?: null);
                }
                if ($applySoaEmail) {
                    $subnet->setSoaEmail($data['soaEmail'] ?: null);
                }
                if ($applySoaRefresh) {
                    $subnet->setSoaRefresh($data['soaRefresh']);
                }
                if ($applySoaRetry) {
                    $subnet->setSoaRetry($data['soaRetry']);
                }
                if ($applySoaExpire) {
                    $subnet->setSoaExpire($data['soaExpire']);
                }
                if ($applySoaTtl) {
                    $subnet->setSoaTtl($data['soaTtl']);
                }
                if ($applyViews) {
                    $newViews = $data['views'] ?? [];
                    if ($viewsMode === 'replace') {
                        foreach ($subnet->getViews()->toArray() as $view) {
                            $subnet->removeView($view);
                        }
                        foreach ($newViews as $view) {
                            $subnet->addView($view);
                        }
                    } elseif ($viewsMode === 'add') {
                        foreach ($newViews as $view) {
                            $subnet->addView($view);
                        }
                    } else {
                        foreach ($newViews as $view) {
                            $subnet->removeView($view);
                        }
                    }
                }
                if ($applyDnssec) {
                    $subnet->setDnssecPolicy($data['dnssecPolicy']);
                }
                if ($applyRetention) {
                    $subnet->setLeaseRetentionDays($data['leaseRetentionDays']);
                }
            }

            $em->flush();
            $count = count($subnets);
            $this->addFlash('success', sprintf('Updated %d subnet%s.', $count, $count !== 1 ? 's' : ''));
            return $this->redirectToRoute('subnet_index');
        }

        return $this->render('subnet/bulk_edit.html.twig', [
            'subnets' => $subnets,
            'form'    => $form,
            'ids'     => $ids,
        ]);
    }
}

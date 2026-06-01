<?php

namespace App\Controller;

use App\Entity\SubnetRecord;
use App\Enum\RecordType;
use App\Form\SubnetBulkEditType;
use App\Repository\DnsViewRepository;
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
    public function bulkEdit(Request $request, SubnetRepository $repo, EntityManagerInterface $em, DnsViewRepository $viewRepo): Response
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

            $recordsApplied = 0;
            if (!empty($post['apply_records'])) {
                $allViewsById = [];
                foreach ($viewRepo->findAll() as $v) {
                    $allViewsById[$v->getId()] = $v;
                }
                foreach ((array)($post['record_templates'] ?? []) as $tpl) {
                    $hostname = trim($tpl['hostname'] ?? '');
                    $typeStr  = $tpl['type'] ?? '';
                    $value    = trim($tpl['value'] ?? '');
                    $ttlRaw   = $tpl['ttl'] ?? '';
                    $ttl      = $ttlRaw !== '' ? (int)$ttlRaw : null;
                    $viewIds  = array_map('intval', (array)($tpl['views'] ?? []));
                    if ($hostname === '' || $value === '' || !RecordType::tryFrom($typeStr)) {
                        continue;
                    }
                    $type = RecordType::from($typeStr);
                    $recordsApplied++;
                    foreach ($subnets as $subnet) {
                        $record = (new SubnetRecord())
                            ->setSubnet($subnet)
                            ->setHostname($hostname)
                            ->setType($type)
                            ->setValue($value)
                            ->setTtl($ttl);
                        $subnetViewIds = $subnet->getViews()->map(fn($v) => $v->getId())->toArray();
                        foreach ($viewIds as $vid) {
                            if (in_array($vid, $subnetViewIds, true) && isset($allViewsById[$vid])) {
                                $record->addView($allViewsById[$vid]);
                            }
                        }
                        $em->persist($record);
                    }
                }
            }

            $em->flush();
            $count = count($subnets);
            $msg   = sprintf('Updated %d subnet%s.', $count, $count !== 1 ? 's' : '');
            if ($recordsApplied > 0) {
                $msg .= sprintf(' Added %d record%s to each.', $recordsApplied, $recordsApplied !== 1 ? 's' : '');
            }
            $this->addFlash('success', $msg);
            return $this->redirectToRoute('subnet_index');
        }

        return $this->render('subnet/bulk_edit.html.twig', [
            'subnets'      => $subnets,
            'form'         => $form,
            'ids'          => $ids,
            'all_views'    => $viewRepo->findBy([], ['name' => 'ASC']),
            'record_types' => RecordType::cases(),
        ]);
    }
}

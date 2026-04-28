<?php

namespace App\Controller;

use App\Entity\Tag;
use App\Form\TagType;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TagController extends AbstractController
{
    #[Route('/tags', name: 'tag_index', methods: ['GET'])]
    public function index(TagRepository $repo): Response
    {
        return $this->render('tag/index.html.twig', [
            'tags' => $repo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/tags/new', name: 'tag_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $tag  = new Tag();
        $form = $this->createForm(TagType::class, $tag);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($tag);
            $em->flush();
            $this->addFlash('success', 'Tag "' . $tag->getName() . '" added.');
            return $this->redirectToRoute('tag_index');
        }

        return $this->render('tag/form.html.twig', [
            'form'  => $form,
            'tag'   => $tag,
            'title' => 'Add Tag',
        ]);
    }

    #[Route('/tags/{id}/edit', name: 'tag_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Tag $tag, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(TagType::class, $tag);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Tag updated.');
            return $this->redirectToRoute('tag_index');
        }

        return $this->render('tag/form.html.twig', [
            'form'  => $form,
            'tag'   => $tag,
            'title' => 'Edit Tag: ' . $tag->getName(),
        ]);
    }

    #[Route('/tags/{id}/delete', name: 'tag_delete', methods: ['POST'])]
    public function delete(Request $request, Tag $tag, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete_tag_' . $tag->getId(), $request->request->get('_token'))) {
            $em->remove($tag);
            $em->flush();
            $this->addFlash('success', 'Tag deleted.');
        }
        return $this->redirectToRoute('tag_index');
    }

    #[Route('/api/tags', name: 'api_tags_search', methods: ['GET'])]
    public function apiSearch(Request $request, TagRepository $repo): JsonResponse
    {
        $q    = trim($request->query->getString('q'));
        $tags = $q === ''
            ? $repo->findBy([], ['name' => 'ASC'], 20)
            : $repo->createQueryBuilder('t')
                ->where('t.name LIKE :q')
                ->setParameter('q', '%' . $q . '%')
                ->orderBy('t.name', 'ASC')
                ->setMaxResults(20)
                ->getQuery()
                ->getResult();

        return $this->json(
            array_map(fn(Tag $t) => ['value' => (string) $t->getId(), 'text' => $t->getName()], $tags)
        );
    }

    #[Route('/api/tags', name: 'api_tags_create', methods: ['POST'])]
    public function apiCreate(Request $request, TagRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $name = trim($data['name'] ?? '');

        if ($name === '') {
            return $this->json(['error' => 'Name is required'], 400);
        }

        $tag = $repo->findOneBy(['name' => $name]);
        if (!$tag) {
            $tag = new Tag();
            $tag->setName($name);
            $em->persist($tag);
            $em->flush();
        }

        return $this->json(['value' => (string) $tag->getId(), 'text' => $tag->getName()], 201);
    }
}

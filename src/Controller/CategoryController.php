<?php

namespace App\Controller;

use App\Entity\Category;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/category', name: 'app_api_category_')]
final class CategoryController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private SerializerInterface $serializer,
        private UrlGeneratorInterface $urlGenerator
    ) {}
    #[Route(name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $category = $this->manager->getRepository(Category::class)->findAll();
        $categoryList = array_map(function (Category $category) {
            return [
                'id' => $category->getId(),
                'name' => $category->getName(),
            ];
        }, $category);

        return new JsonResponse([
            'status' => 'success',
            'categories' => $categoryList,
        ], Response::HTTP_OK);
    }
    #[Route(name: 'new', methods: ['POST'])]
    public function new(Request $request): JsonResponse
    {
        $category = $this->serializer->deserialize($request->getContent(), Category::class, 'json');


        $category->setCreatedAt(new DateTimeImmutable());

        $this->manager->persist($category);
        $this->manager->flush();

        $responseData = $this->serializer
            ->serialize(
                $category,
                'json',
                ['groups' => ['category:read']]
            );
        $location = $this->urlGenerator->generate(
            'app_api_category_show',
            ['id' => $category->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return new JsonResponse($responseData, Response::HTTP_CREATED, ["location" => $location], true);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $category = $this->manager->getRepository(Category::class)->findOneBy(['id' => $id]);

        if ($category) {
            $responseData = $this->serializer
                ->serialize(
                    $category,
                    'json',
                    ['groups' => ['category:read']]
                );
            return new JsonResponse($responseData, Response::HTTP_OK, [], true);
        }
        return new JsonResponse(null, Response::HTTP_NOT_FOUND);
    }

    #[Route('/{id}', name: 'edit', methods: ['PUT'])]
    public function edit(
        int $id,
        Request $request
    ): JsonResponse {
        $category = $this->manager->getRepository(Category::class)->findOneBy(['id' => $id]);

        if (!$category) {
            return new JsonResponse(
                ['error' => 'Catégorie introuvable.'],
                Response::HTTP_NOT_FOUND
            );
        }
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(
                ['error' => 'Données invalides.'],
                Response::HTTP_BAD_REQUEST
            );
        }
        if (isset($data['name'])) {
            $category->setName(htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8'));
        } else {
            return new JsonResponse(
                ['error' => 'Données invalides.'],
                Response::HTTP_BAD_REQUEST
            );
        }
        $category->setUpdatedAt(new DateTimeImmutable());

        $this->manager->flush();

        $responseData = $this->serializer
            ->serialize(
                $category,
                'json',
                ['groups' => ['category:read']]
            );

        $location = $this->urlGenerator->generate(
            'app_api_category_show',
            ['id' => $category->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return new JsonResponse(
            $responseData,
            Response::HTTP_CREATED,
            ["location" => $location],
            true
        );
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $category = $this->manager->getRepository(Category::class)->findOneBy(['id' => $id]);

        if (!$category) {
            return new JsonResponse(
                [
                    'status' => 'error',
                    'message' => 'Catégorie introuvable.'
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        $this->manager->remove($category);
        $this->manager->flush();

        return new JsonResponse(
            [
                'status' => 'success',
                'message' => 'Catégorie supprimée avec succès'
            ],
            Response::HTTP_OK
        );
    }
}

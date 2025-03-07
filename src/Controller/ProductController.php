<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Picture;
use App\Entity\Product;
use App\Repository\ProductRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/products', name: 'app_api_product_')]
final class ProductController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private SerializerInterface $serializer,
        private ProductRepository $repository,
        private UrlGeneratorInterface $urlGenerator
    ) {}
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $products = $this->manager->getRepository(Product::class)->findAll();
        $productList = array_map(function (Product $product) {
            return [
                'id' => $product->getId(),
                'title' => $product->getTitle(),
                'description' => $product->getDescription(),
                'price' => $product->getPrice(),
                'availability' => $product->isAvailability(),
                'picture' => $product->getPicture(),
            ];
        }, $products);
        return new JsonResponse([
            'status' => 'success',
            'products' => $productList
        ], Response::HTTP_OK);
    }

    #[Route(name: 'new', methods: ['POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $manager
    ): JsonResponse {
        // Vérifier si la requête contient du JSON valide
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!$data) {
            return new JsonResponse(
                ['error' => 'JSON invalide.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Désérialisation du produit
        $product = $this->serializer->deserialize(
            $request->getContent(),
            Product::class,
            'json'
        );

        // Gestion de la catégorie (ManyToMany)
        if (isset($data['category']) && is_array($data['category'])) {
            foreach ($data['category'] as $categoryId) {
                $category = $manager->getRepository(Category::class)->find($categoryId);
                if ($category) {
                    $product->addCategory($category);
                } else {
                    return new JsonResponse(
                        ['error' => "Catégorie ID $categoryId introuvable."],
                        Response::HTTP_NOT_FOUND
                    );
                }
            }
        }
        if (isset($data['picture']) && is_array($data['picture'])) {
            foreach ($data['picture'] as $pictureId) {
                $picture = $manager->getRepository(Picture::class)->find($pictureId);
                if (!$picture) {
                    return new JsonResponse(
                        ['error' => "Image ID $pictureId introuvable."],
                        Response::HTTP_NOT_FOUND
                    );
                }

                dump($picture);
                die();
                $product->addPicture($picture);
            }
        }

        // Ajout de la date de création
        $product->setCreatedAt(new DateTimeImmutable());

        // Persistance et sauvegarde en base de données
        $manager->persist($product);
        dump($product);
        $manager->flush();

        // Sérialisation de la réponse
        $responseData = json_decode(
            $this->serializer
                ->serialize(
                    $product,
                    'json',
                    ['groups' => 'product:read']
                ),
            true
        ); // Convertir en tableau

        // Supprimer updatedAt s'il est null
        if (!isset($responseData['updatedAt'])) {
            unset($responseData['updatedAt']);
        }

        if (!$product->getId()) {
            return new JsonResponse(['error' => "Problème lors de l'enregistrement du produit."], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        // Génération de l'URL du nouveau produit
        $location = $this->urlGenerator->generate(
            'app_api_product_show',
            ['id' => $product->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return new JsonResponse(
            $responseData,
            Response::HTTP_CREATED,
            ["Location" => $location]
        );
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $product = $this->repository
            ->findOneBy(['id' => $id]);

        if ($product) {
            $responseData = $this->serializer
                ->serialize(
                    $product,
                    'json',
                    ['groups' => 'product:read']
                );
            $productArray = json_decode(
                $responseData,
                true
            );
            if ($product->getUpdatedAt()) {
                $productArray['updatedAt'] = $product
                    ->getUpdatedAt()
                    ->format('Y-m-d H:i:s');
            } else {
                unset($productArray['updatedAt']);
            }
            return new JsonResponse(
                $responseData,
                Response::HTTP_OK,
                [],
                true
            );
        }
        return new JsonResponse(
            null,
            Response::HTTP_NOT_FOUND
        );
    }
    #[Route('/{id}', name: 'edit', methods: ['PUT'])]
    public function edit(
        int $id,
        Request $request,
        EntityManagerInterface $manager
    ): JsonResponse {
        $product = $this->manager->getRepository(
            Product::class
        )
            ->find($id);

        if (!$product) {
            return new JsonResponse(
                ['error' => 'Produit introuvable.'],
                Response::HTTP_NOT_FOUND
            );
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(
                [
                    'error' => 'Données invalides.'
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->serializer->deserialize(
            $request->getContent(),
            Product::class,
            'json',
            [AbstractNormalizer::OBJECT_TO_POPULATE => $product]
        );

        // Gestion de la catégorie (ManyToMany)
        if (isset($data['category']) && is_array($data['category'])) {
            // Supprimer toutes les anciennes catégories
            foreach ($product->getCategories() as $existingCategory) {
                $product->removeCategory($existingCategory);
            }

            // Ajouter les nouvelles catégories
            foreach ($data['category'] as $categoryId) {
                $category = $manager->getRepository(Category::class)->find($categoryId);
                if ($category) {
                    $product->addCategory($category);
                } else {
                    return new JsonResponse(
                        ['error' => "Catégorie ID $categoryId introuvable."],
                        Response::HTTP_NOT_FOUND
                    );
                }
            }
        }
        if (isset($data['picture']) && is_array($data['picture'])) {
            foreach ($data['picture'] as $pictureId) {
                $picture = $manager->getRepository(Picture::class)->find($pictureId);
                if ($picture) {
                    $product->setPicture($picture);
                } else {
                    return new JsonResponse(
                        ['error' => "Image ID $pictureId introuvable."],
                        Response::HTTP_NOT_FOUND
                    );
                }
            }
        }


        $product->setUpdatedAt(new \DateTimeImmutable());

        $this->manager->flush();

        $responseData = json_decode(
            $this->serializer
                ->serialize(
                    $product,
                    'json',
                    ['groups' => 'product:read']
                ),
            true
        ); // Convertir en tableau

        // Corriger la structure de categories
        if (isset($responseData['categories']) && is_array($responseData['categories'])) {
            $responseData['categories'] = array_values($responseData['categories']);
        }

        // Génération de l'URL du nouveau produit
        $location = $this->urlGenerator->generate(
            'app_api_product_show',
            ['id' => $product->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return new JsonResponse(
            $responseData,
            Response::HTTP_OK,
            ["Location" => $location]
        );
    }
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $product = $this->manager->getRepository(Product::class)->findOneBy(['id' => $id]);

        if (!$product) {
            return new JsonResponse(
                [
                    'status' => 'error',
                    'message' => 'Produit introuvable.'
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        $this->manager->remove($product);
        $this->manager->flush();

        return new JsonResponse(
            ['status' => 'success', 'message' => 'Produit supprimé avec succès'],
            Response::HTTP_OK
        );
    }
}

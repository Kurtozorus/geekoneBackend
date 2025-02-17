<?php

namespace App\Controller;

use App\Entity\Category;
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
        private ProductRepository $repository,
        private SerializerInterface $serializer,
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

        // Ajout de la date de création
        $product->setCreatedAt(new DateTimeImmutable());

        // Persistance et sauvegarde en base de données
        $manager->persist($product);
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
        $product = $this->manager->getRepository(Product::class)->findOneBy(['id' => $id]);

        if ($product) {
            $this->serializer->serialize($product, 'json', ['groups' => 'product:read']);
            return new JsonResponse(
                [
                    'product' => [
                        'id' => $product->getId(),
                        'title' => $product->getTitle(),
                        'description' => $product->getDescription(),
                        'price' => $product->getPrice(),
                        'availability' => $product->isAvailability(),
                        'picture' => $product->getPicture(),
                        'createdAt' => $product->getCreatedAt()->format("d-m-Y")
                    ],
                ],
                JsonResponse::HTTP_CREATED,
            );
        }
    }
    #[Route('/{id}', name: 'edit', methods: ['PUT'])]
    public function edit(
        int $id,
        Request $request,
        EntityManagerInterface $manager
    ): JsonResponse {
        $product = $this->manager->getRepository(Product::class)->find($id);

        if (!$product) {
            return new JsonResponse(['error' => 'Produit introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Données invalides.'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['title'])) {
            $product->setTitle(htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8'));
        }
        if (isset($data['description'])) {
            $product->setDescription(strip_tags($data['description']));
        }
        if (isset($data['price'])) {
            $product->setPrice((float) $data['price']);
        }
        if (isset($data['availability'])) {
            $product->setAvailability((bool) $data['availability']);
        }

        if (isset($data['category'])) {
            $category = $this->manager->getRepository(Category::class)->find($data['category']);
            if (!$category) {
                return new JsonResponse(['error' => 'Catégorie introuvable.'], Response::HTTP_BAD_REQUEST);
            }
            $product->setCategory($category);
        }

        if (isset($data['picture'])) {
            $picture = $this->manager->getRepository(Picture::class)->find($data['picture']);
            if (!$picture) {
                return new JsonResponse(['error' => 'Image introuvable.'], Response::HTTP_BAD_REQUEST);
            }
            $product->setPicture($picture);
        }

        $product->setUpdatedAt(new \DateTimeImmutable());

        $this->manager->flush();

        return new JsonResponse([
            'status' => 'success',
            'message' => 'Produit modifié avec succès',
            'product' => [
                'id' => $product->getId(),
                'title' => $product->getTitle(),
                'description' => $product->getDescription(),
                'price' => $product->getPrice(),
                'availability' => $product->isAvailability(),
                'category' => $product->getCategory() ? $product->getCategory()->getName() : null,
                'picture' => $product->getPicture() ? $product->getPicture()->getId() : null,
                'createdAt' => $product->getCreatedAt()->format("d-m-Y"),
                'updatedAt' => $product->getUpdatedAt()->format("d-m-Y")
            ]
        ], Response::HTTP_OK);

        // if (!$product) {
        //     return new JsonResponse(
        //         ['error' => 'Produit introuvable.'],
        //         Response::HTTP_NOT_FOUND
        //     );
        // }

        // $title = $request->request->get('title');
        // $description = $request->request->get('description');
        // $price = $request->request->get('price');
        // $availability = $request->request->get('availability');
        // if ($title) {
        //     $product->setTitle($title);
        // }
        // if ($description) {
        //     $product->setDescription($description);
        // }
        // if ($price) {
        //     $product->setPrice((float) $price);
        // }
        // if ($availability) {
        //     $product->setAvailability($availability);
        // }


        // $product->setUpdatedAt(new DateTimeImmutable());
        // $this->manager->flush();

        // $productUrl = $urlGenerator->generate(
        //     'app_api_product_show',
        //     ['id' => $product->getId()],
        //      UrlGeneratorInterface::ABSOLUTE_URL
        // );

        // return new JsonResponse(
        //         [
        //             'status' => 'success',
        //             'message' => 'Produit modifier avec succes!',
        //             'product' => [
        //                 'id' => $product->getId(),
        //                 'title' => $product->getTitle(),
        //                 'description' => $product->getDescription(),
        //                 'price' => $product->getPrice(),
        //                 'availability' => $product->isAvailability(),
        //                 'picture' => $product->getPicture(),
        //                 'productUrl' => $productUrl,
        //                 'createdAt' => $product->getCreatedAt()->format("d-m-Y"),
        //                 'updatedAt' => $product->getUpdatedAt()->format("d-m-Y")
        //             ]
        //             ],JsonResponse::HTTP_OK,
        //     );

        //Assigner une image au produit
        // if (isset($data['picture'])) {
        //     $picture = $manager->getRepository(Picture::class)->find($data['picture']);
        //     if ($picture) {
        //         $product->setPicture($picture);
        //     } else {
        //         return new JsonResponse(
        //                 ['error' => 'Image introuvable.'] ,
        //                 Response::HTTP_BAD_REQUEST
        //         );
        //     }
        // }
        // $this->manager->persist($product);
        // $this->manager->flush();

        //sérialise l'objet pour renvoyé une réponse Json
        //   $this->serializer->serialize(
        //      $product,
        //      'json',
        //      ['groups' => 'product:read']
        //  );
        //   $this->urlGenerator->generate(
        //      'app_api_product_show',
        //      ['id' => $product->getId()],
        //      UrlGeneratorInterface::ABSOLUTE_URL
        //  );


        // return new JsonResponse(
        //     [
        //         'status' => 'success',
        //         'message' => 'Produit créer avec succès',
        //         'product' => [
        //             'id' => $product->getId(),
        //             'title' => $product->getTitle(),
        //             'description' => $product->getDescription(),
        //             'price' => $product->getPrice(),
        //             'availability' => $product->isAvailability(),
        //             'picture' => $product->getPicture(),
        //             'createdAt' => $product->getCreatedAt()->format("d-m-Y")
        //         ],
        //     ],JsonResponse::HTTP_CREATED,
        // );
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
            [
                'status' => 'success',
                'message' => 'Produit supprimée avec succès'
            ],
            Response::HTTP_NO_CONTENT
        );
    }
}

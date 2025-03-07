<?php

namespace App\Controller;

use App\Entity\Booking;
use App\Entity\Product;
use App\Repository\BookingRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/booking', name: 'app_api_booking_')]
final class BookingController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private BookingRepository $repository,
        private SerializerInterface $serializer,
        private UrlGeneratorInterface $urlGenerator
    ) {}

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $bookings = $this->manager->getRepository(Booking::class)->findAll();
        $bookingList = array_map(function (Booking $booking) {
            return [
                'id' => $booking->getId(),
                'quantite' => $booking->getQuantite(),
                'product' => array_map(fn(Product $product) => [
                    'id' => $product->getId(),
                    'title' => $product->getTitle(),
                    'availability' => $product->isAvailability() ? 'Disponible' : 'Indisponible'
                ], $booking->getProduct()->toArray()),
                'status' => $booking->getStatus(),
                'createdAt' => $booking->getCreatedAt()->format("d-m-Y H:i:s"),
                'updatedAt' => $booking->getUpdatedAt(),
                'user' => [
                    'id' => $booking->getUser()->getId(),
                    'firstName' => $booking->getUser()->getFirstName(),
                    'lastName' => $booking->getUser()->getLastName(),
                ],
            ];
        }, $bookings);

        return new JsonResponse([
            'status' => 'success',
            'bookings' => $bookingList
        ], Response::HTTP_OK);
    }

    #[Route(name: 'new', methods: ['POST'])]
    public function new(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'JSON invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $booking = $this->serializer->deserialize($request->getContent(), Booking::class, 'json');
        $booking->setCreatedAt(new DateTimeImmutable());

        // Associer l'utilisateur connecté
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Utilisateur non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }
        $booking->setUser($user);


        if (isset($data['product']) && is_array($data['product'])) {
            foreach ($data['product'] as $productItem) {
                $productId = is_array($productItem) ? ($productItem['id'] ?? null) : $productItem;
                if ($productId) {
                    $product = $this->manager->getRepository(Product::class)->find($productId);
                    if ($product) {
                        $booking->addProduct($product);
                    }
                }
            }
        }

        $this->manager->persist($booking);
        $this->manager->flush();

        $responseData = json_decode(
            $this->serializer
                ->serialize(
                    $booking,
                    'json',
                    ['groups' => ['booking:read']]
                ),
            true
        );
        if (!isset($responseData['updatedAt'])) {
            unset($responseData['updatedAt']);
        }

        $location = $this->urlGenerator->generate('app_api_booking_show', ['id' => $booking->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return new JsonResponse($responseData, Response::HTTP_CREATED, ['Location' => $location]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $booking = $this->repository
            ->findOneBy(['id' => $id]);
        if ($booking) {
            $responseData = $this->serializer
                ->serialize(
                    $booking,
                    'json',
                    ['groups' => ['booking:read']]
                );
            $bookingArray = json_decode(
                $responseData,
                true
            );
            if ($booking->getUpdatedAt()) {
                $bookingArray['updatedAt'] = $booking
                    ->getUpdatedAt()
                    ->format('Y-m-d H:i:s');
            } else {
                unset($bookingArray['updatedAt']);
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

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $booking = $this->manager->getRepository(Booking::class)->findOneBy(['id' => $id]);

        if (!$booking) {
            return new JsonResponse(
                [
                    'status' => 'error',
                    'message' => 'Réservation introuvable.'
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        $this->manager->remove($booking);
        $this->manager->flush();

        return new JsonResponse(
            ['status' => 'success', 'message' => 'Réservation supprimé avec succès'],
            Response::HTTP_OK
        );
    }
}

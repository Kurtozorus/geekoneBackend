<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Entity;
use PHPUnit\Util\Json;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Response, Request};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api', name: 'app_api_')]
final class SecurityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $manager,
        private UserRepository $repository,
        private SerializerInterface $serializer,
        private UrlGeneratorInterface $urlGenerator,
        private UserPasswordHasherInterface $passwordHasher
    ) {}
    #[Route('/registration', name: 'registration', methods: ['POST'])]
    public function register(Request $request, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        $user = $this->serializer->deserialize(
            $request->getContent(),
            User::class,
            'json'
        );

        // Si l'utilisateur tente de créer un administrateur, vérifiez s'il existe déjà un administrateur
        if (in_array(
            "ROLE_ADMIN",
            $user->getRoles()
        )) {
            $existingAdmin = $this->manager
                ->getRepository(User::class)
                ->findOneByRole('ROLE_ADMIN');
            if (!$existingAdmin) {
                $user->setroles(['ROLE_ADMIN']);
            } else {
                return new JsonResponse(
                    ['error' => 'Un compte administrateur existe déjà'],
                    Response::HTTP_FORBIDDEN
                );
            }
        }
        $user->setPassword(
            $passwordHasher
                ->hashPassword(
                    $user,
                    $user
                        ->getPassword()
                )
        );
        $user->setCreatedAt(new \DateTimeImmutable());

        $this->manager->persist($user);
        $this->manager->flush();
        return new JsonResponse(
            [
                'user' => $user->getUserIdentifier(),
                'apiToken' => $user->getApiToken(),
                'roles' => $user->getRoles(),
            ],
            Response::HTTP_CREATED
        );
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return new JsonResponse([
                'message' => 'Informations de connexion incorrectes',
            ], Response::HTTP_UNAUTHORIZED);
        }
        return new JsonResponse(
            [
                'user' => $user->getUserIdentifier(),
                'apiToken' => $user->getApiToken(),
                'roles' => $user->getRoles()
            ],
        );
    }
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $users = $this->repository->findAll();
        $userList = array_map(function (User $user) {
            return [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ];
        }, $users);
        return new JsonResponse([
            'status' => 'success',
            'users' => $userList,
        ], Response::HTTP_OK);
    }
    #[Route('/account/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        $responseData =
            $this->serializer
            ->serialize(
                $user,
                'json',
                [
                    AbstractNormalizer::ATTRIBUTES => [
                        'id',
                        'email',
                        'firstName',
                        'lastName',
                        'roles',
                        'createdAt',
                        'updatedAt'
                    ]
                ]
            );

        return new JsonResponse(
            $responseData,
            Response::HTTP_OK,
            [],
            true
        );
    }
    #[Route('/account/edit', name: 'edit', methods: ['PUT'])]
    public function edit(
        Request $request
    ): JsonResponse {
        $user = $this->serializer
            ->deserialize(
                $request->getContent(),
                User::class,
                'json',
                [AbstractNormalizer::OBJECT_TO_POPULATE => $this->getUser()],
            );

        $user->setUpdatedAt(new \DateTimeImmutable());

        if (isset($request->toArray()['password'])) {
            $user->setPassword(
                $this->passwordHasher
                    ->hashPassword(
                        $user,
                        $user->getPassword()
                    )
            );
        }

        $this->manager->flush();

        $responseData =
            $this->serializer
            ->serialize(
                $user,
                'json',
                [
                    AbstractNormalizer::ATTRIBUTES => [
                        'id',
                        'email',
                        'firstName',
                        'lastName',
                        'roles',
                        'createdAt',
                        'updatedAt'
                    ]
                ]
            );

        return new JsonResponse(
            $responseData,
            Response::HTTP_OK,
            [],
            true
        );
    }

    #[Route('/assign-role', name: 'asssignRole', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function assignRole(
        Request $request
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'];
        $roles = $data['roles'];

        $user = $this->manager
            ->getRepository(
                User::class
            )
            ->findOneBy(
                ['email' => $email]
            );
        if (!$user) {
            return new JsonResponse(['message' => 'Utilisateur non trouvé'], Response::HTTP_NOT_FOUND);
        }
        // On s'assure que les rôles sont valides
        $validRoles = ['ROLE_EMPLOYEE', 'ROLE_MODERATOR'];
        foreach ($roles as $role) {
            if (!in_array($role, $validRoles, true)) {
                return new JsonResponse(['message' => "Le rôle '$role' n'est pas valide"], Response::HTTP_BAD_REQUEST);
            }
        }
        $user->setRoles($roles);
        $this->manager->flush();
        return new JsonResponse(
            ['message' => 'Le compte a bien été modifié avec succès'],
            Response::HTTP_OK
        );
    }
    #[Route('/{id}', name: 'role', methods: ['POST'])]
    public function role(int $id): JsonResponse
    {
        $user = $this->manager->getRepository(User::class)->findOneBy(['id' => $id]);
        if (!$user) {
            return new JsonResponse(
                [
                    'status' => 'error',
                    'message' => 'Utilisateur introuvable.'
                ],
                Response::HTTP_NOT_FOUND
            );
        }
        $user->addRole('ROLE_ADMIN');
        $this->manager->flush();
        return new JsonResponse(
            ['status' => 'success', 'message' => 'Votre compte a bien été modifié avec succès'],
            Response::HTTP_OK
        );
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->manager->getRepository(User::class)->findOneBy(['id' => $id]);
        if (!$user) {
            return new JsonResponse(
                [
                    'status' => 'error',
                    'message' => 'Utilisateur introuvable.'
                ],
                Response::HTTP_NOT_FOUND
            );
        }
        $this->manager->remove($user);
        $this->manager->flush();
        return new JsonResponse(
            ['status' => 'success', 'message' => 'Votre compte a bien été supprimé avec succès'],
            Response::HTTP_OK
        );
    }
}

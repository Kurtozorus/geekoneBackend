<?php

namespace App\Controller;

use App\Entity\Picture;
use App\Repository\PictureRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Service\PictureUploaderService;

#[Route('api/picture', name: 'app_api_picture_')]
final class PictureController extends AbstractController
{
    private string $uploadDir;
    private KernelInterface $kernel;
    public function __construct(
        private EntityManagerInterface $manager,
        private PictureRepository $repository,
        private SerializerInterface $serializer,
        private UrlGeneratorInterface $urlGenerator,
        private PictureUploaderService $pictureUploader,
        private ValidatorInterface $validator, // Injection du validateur
        KernelInterface $kernel,
    ) {
        $this->kernel = $kernel;
        $this->uploadDir = $kernel->getProjectDir() . '/public/uploads/pictures/';
    }
    
    #[Route(name: 'new', methods: ['POST'])]
    public function new(Request $request): JsonResponse
    {
        $uploadedFile = $request->files->get('picture'); // Récupération du fichier envoyé

        // Vérifier l'extension et le type MIME
        $allowedExtensions = [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp'
        ];
        $allowedMimeTypes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp'
        ];
        // $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());
        // $mimeType = $uploadedFile->getMimeType();

        // if (!in_array($fileExtension, $allowedExtensions) || !in_array($mimeType, $allowedMimeTypes)) {
        //     return new JsonResponse(
        //         ['error' => 'Invalid file type'],
        //         Response::HTTP_BAD_REQUEST
        //     );
        // }
        // $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());
        // $mimeType = $uploadedFile->getMimeType();

        // if (!in_array($fileExtension, $allowedExtensions) || !in_array($mimeType, $allowedMimeTypes)) {
        //     return new JsonResponse(
        //         ['error' => 'Invalid file type'],
        //         Response::HTTP_BAD_REQUEST
        //     );
        // }
        if ($uploadedFile) {
            $fileName = uniqid() . '.' . $uploadedFile->guessExtension();

            try {
                $uploadedFile->move($this->uploadDir, $fileName);
            } catch (FileException $e) {
                return new JsonResponse(['error' => 'File upload failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } else {
            // Sinon, vérifier si c'est une requête JSON avec base64
            $data = json_decode($request->getContent(), true);

            if (!isset($data['fileName']) || !isset($data['fileData'])) {
                return new JsonResponse(['error' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
            }

            $fileName = uniqid() . '-' . $data['fileName'];
            $filePath = $this->uploadDir . $fileName;

            // Convertir base64 en fichier réel
            $decodedData = base64_decode($data['fileData']);
            if ($decodedData === false) {
                return new JsonResponse(['error' => 'Invalid base64 data'], Response::HTTP_BAD_REQUEST);
            }

            file_put_contents($filePath, $decodedData);
        }
        $picture = new Picture();

        // Créer une nouvelle entité Image
        $picture->setImagePath('/uploads/pictures/' . $fileName);
        $picture->setCreatedAt(new \DateTimeImmutable());
        $picture->setUpdatedAt(new \DateTimeImmutable());

        // Sauvegarde dans la base de données
        $this->manager->persist($picture);
        $this->manager->flush();

        // Retourner une réponse
        return new JsonResponse(
            $this->serializer->serialize($picture, 'json'),
            Response::HTTP_CREATED,
            [],
            true
        );
    }
    #[Route('/{id}', name: 'show', methods: 'GET')]
    public function show(int $id): BinaryFileResponse
    {
        $picture = $this->repository->findOneBy(['id' => $id]);
        // Vérification de l'existence de l'image
        if (!$picture) {
            throw $this->createNotFoundException('Image introuvable');
        }

        // Chemin absolu du fichier sur le serveur
        $imagePath = $this->getParameter('kernel.project_dir') . '/public' . $picture->getFilePath();

        // Vérification de l'existence du fichier
        if (!file_exists($imagePath)) {
            throw $this->createNotFoundException('Dossier introuvable');
        }

        // Retourner directement l'image en réponse HTTP
        return new BinaryFileResponse($imagePath);
    }

    #[Route('/{id}', name: 'edit', methods: ['POST'])]
    public function edit(int $id, Request $request): Response
    {
        // Récupération de l'image en base de données
        $image = $this->repository->find($id);

        if (!$image) {
            return new JsonResponse(
                ['error' => 'Image not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Récupérer le fichier envoyé
        $uploadedFile = $request->files->get('image');

        if (!$uploadedFile) {
            return new JsonResponse(
                ['error' => 'No file uploaded'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Vérifier l'extension et le type MIME
        $allowedExtensions = [
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp'
        ];
        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp'
        ];

        $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());
        $mimeType = $uploadedFile->getMimeType();

        if (!in_array($fileExtension, $allowedExtensions) || !in_array($mimeType, $allowedMimeTypes)) {
            return new JsonResponse(
                ['error' => 'Invalid file type'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Supprimer l'ancien fichier s'il existe
        $oldFilePath = $this->getParameter('kernel.project_dir') . '/public' . $image->getFilePath();
        if (file_exists($oldFilePath)) {
            unlink($oldFilePath);
        }

        // Générer un nouveau nom de fichier
        $fileName = uniqid() . '-' . preg_replace(
            '/[^a-zA-Z0-9\._-]/',
            '',
            $uploadedFile->getClientOriginalName()
        );

        // Déplacer le fichier vers le répertoire d'upload
        try {
            if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0775, true)) {
                return new JsonResponse(
                    ['error' => 'Failed to create upload directory'],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }
            $uploadedFile->move($this->uploadDir, $fileName);
        } catch (FileException $e) {
            return new JsonResponse(
                ['error' => 'File upload failed'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // Mettre à jour l'image dans la base de données
        $image->setFilePath('/uploads/images/' . $fileName);
        $image->setUpdatedAt(new DateTimeImmutable());

        $this->manager->flush();

        // Chemin absolu du fichier
        $imagePath = $this->getParameter('kernel.project_dir') . '/public' . $image->getFilePath();

        // Vérification de l'existence du fichier
        if (!file_exists($imagePath)) {
            return new JsonResponse(
                ['error' => 'File not found after upload'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // Retourner une réponse de l'image mise à jour
        return new JsonResponse([
            'id' => $image->getId(),
            'filePath' => $image->getFilePath(), // URL relative de l'image
            'updatedAt' => $image->getUpdatedAt()->format('Y-m-d H:i:s'),
            'message' => 'Image updated successfully'
        ], Response::HTTP_OK);
    }

    //Supprimer une image
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        // Récupérer l'image depuis la base de données
        $image = $this->repository->find($id);

        if (!$image) {
            return new JsonResponse(['error' => 'Image not found'], Response::HTTP_NOT_FOUND);
        }

        // Construire le chemin absolu du fichier
        $filePath = $this->getParameter('kernel.project_dir') . '/public' . $image->getFilePath();

        // Vérifier si le fichier existe et le supprimer
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Supprimer l'image de la base de données
        $this->manager->remove($image);
        $this->manager->flush();

        return new JsonResponse(['message' => 'Image deleted successfully'], Response::HTTP_OK);
    }
}

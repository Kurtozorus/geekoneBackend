<?php

namespace App\Controller;

use App\Entity\Picture;
use App\Repository\PictureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/pictures')]
class PictureController extends AbstractController
{
    private string $uploadDir;

    public function __construct(private EntityManagerInterface $manager)
    {
        $this->uploadDir = __DIR__ . '/../../public/uploads/pictures/';
    }

    /**
     * 🆕 Ajouter une nouvelle image (upload fichier ou base64)
     */
    #[Route('/new', name: 'picture_new', methods: ['POST'])]
    public function new(
        Request $request,
        ValidatorInterface $validator,
        SluggerInterface $slugger
    ): JsonResponse {
        // Récupération des données depuis form-data
        $title = $request->get('title');
        $slug = $request->get('slug');
        $fileData = $request->get('fileData');  // Pour les images en base64
        $fileName = $request->get('fileName');
        $pictureFile = $request->files->get('picture'); // Récupérer le fichier image

        // Vérification des données
        if (!$title) {
            return new JsonResponse(['error' => 'Title is required'], Response::HTTP_BAD_REQUEST);
        }
        if (!$slug) {
            return new JsonResponse(['error' => 'Slug is required'], Response::HTTP_BAD_REQUEST);
        }
        if (!$pictureFile && empty($fileData)) {
            return new JsonResponse(['error' => 'No file data provided'], Response::HTTP_BAD_REQUEST);
        }

        // Gestion de l'image
        $fileName = uniqid(); // Un nom de fichier unique
        if ($pictureFile) {
            // Si un fichier est téléchargé
            $extension = $pictureFile->guessExtension();
            $fileName .= '.' . $extension;
            $pictureFile->move($this->uploadDir, $fileName);
            $fileContent = file_get_contents($this->uploadDir . $fileName);
        } else {
            // Si l'image est envoyée en base64
            $fileName .= '-' . $fileName;
            $fileContent = base64_decode($fileData);
            file_put_contents($this->uploadDir . $fileName, $fileContent);
        }

        // Générer l'URL du fichier
        $fileUrl = $request->getSchemeAndHttpHost() . '/uploads/pictures/' . $fileName;

        // Création de l'entité Picture
        $picture = new Picture();
        $picture->setFilePath($fileUrl);
        $picture->setImageData($fileContent);  // L'image en BLOB
        $picture->setImagePath($fileName);
        $picture->setTitle($title, $slugger);  // Titre et slug (si nécessaire)
        $picture->setSlug($slug);  // Assigner le slug fourni ou généré
        $picture->setCreatedAt(new \DateTimeImmutable());
        // Validation de l'entité
        $errors = $validator->validate($picture);
        if (count($errors) > 0) {
            return new JsonResponse(
                ['error' => (string) $errors],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Persister l'entité et sauvegarder
        $this->manager->persist($picture);
        $this->manager->flush();

        return new JsonResponse(
            ['message' => 'Picture added successfully'],
            Response::HTTP_CREATED
        );
    }




    // #[Route('/{id}', name: 'show', methods: 'GET')]
    // public function show(int $id): BinaryFileResponse
    // {
    //     $picture = $this->repository->findOneBy(['id' => $id]);
    //     // Vérification de l'existence de l'picture
    //     if (!$picture) {
    //         throw $this->createNotFoundException('picture introuvable');
    //     }

    //     // Chemin absolu du fichier sur le serveur
    //     $picturePath = $this->getParameter('kernel.project_dir') . '/public' . $picture->getFilePath();

    //     // Vérification de l'existence du fichier
    //     if (!file_exists($picturePath)) {
    //         throw $this->createNotFoundException('Dossier introuvable');
    //     }

    //     // Retourner directement l'picture en réponse HTTP
    //     return new BinaryFileResponse($picturePath);
    // }

    // #[Route('/{id}', name: 'edit', methods: ['POST'])]
    // public function edit(int $id, Request $request): Response
    // {
    //     // Récupération de l'picture en base de données
    //     $picture = $this->repository->find($id);

    //     if (!$picture) {
    //         return new JsonResponse(
    //             ['error' => 'picture not found'],
    //             Response::HTTP_NOT_FOUND
    //         );
    //     }

    //     // Récupérer le fichier envoyé
    //     $uploadedFile = $request->files->get('picture');

    //     if (!$uploadedFile) {
    //         return new JsonResponse(
    //             ['error' => 'No file uploaded'],
    //             Response::HTTP_BAD_REQUEST
    //         );
    //     }

    //     // Vérifier l'extension et le type MIME
    //     $allowedExtensions = [
    //         'jpg',
    //         'jpeg',
    //         'png',
    //         'gif',
    //         'webp'
    //     ];
    //     $allowedMimeTypes = [
    //         'picture/jpeg',
    //         'picture/png',
    //         'picture/gif',
    //         'picture/webp'
    //     ];

    //     $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());
    //     $mimeType = $uploadedFile->getMimeType();

    //     if (!in_array($fileExtension, $allowedExtensions) || !in_array($mimeType, $allowedMimeTypes)) {
    //         return new JsonResponse(
    //             ['error' => 'Invalid file type'],
    //             Response::HTTP_BAD_REQUEST
    //         );
    //     }

    //     // Supprimer l'ancien fichier s'il existe
    //     $oldFilePath = $this->getParameter('kernel.project_dir') . '/public' . $picture->getFilePath();
    //     if (file_exists($oldFilePath)) {
    //         unlink($oldFilePath);
    //     }

    //     // Générer un nouveau nom de fichier
    //     $fileName = uniqid() . '-' . preg_replace(
    //         '/[^a-zA-Z0-9\._-]/',
    //         '',
    //         $uploadedFile->getClientOriginalName()
    //     );

    //     // Déplacer le fichier vers le répertoire d'upload
    //     try {
    //         if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0775, true)) {
    //             return new JsonResponse(
    //                 ['error' => 'Failed to create upload directory'],
    //                 Response::HTTP_INTERNAL_SERVER_ERROR
    //             );
    //         }
    //         $uploadedFile->move($this->uploadDir, $fileName);
    //     } catch (FileException $e) {
    //         return new JsonResponse(
    //             ['error' => 'File upload failed'],
    //             Response::HTTP_INTERNAL_SERVER_ERROR
    //         );
    //     }

    //     // Mettre à jour l'picture dans la base de données
    //     $picture->setFilePath('/uploads/pictures/' . $fileName);
    //     $picture->setUpdatedAt(new DateTimeImmutable());

    //     $this->manager->flush();

    //     // Chemin absolu du fichier
    //     $picturePath = $this->getParameter('kernel.project_dir') . '/public' . $picture->getFilePath();

    //     // Vérification de l'existence du fichier
    //     if (!file_exists($picturePath)) {
    //         return new JsonResponse(
    //             ['error' => 'File not found after upload'],
    //             Response::HTTP_INTERNAL_SERVER_ERROR
    //         );
    //     }

    //     // Retourner une réponse de l'picture mise à jour
    //     return new JsonResponse([
    //         'id' => $picture->getId(),
    //         'filePath' => $picture->getFilePath(), // URL relative de l'picture
    //         'updatedAt' => $picture->getUpdatedAt()->format('Y-m-d H:i:s'),
    //         'message' => 'picture updated successfully'
    //     ], Response::HTTP_OK);
    // }

    // //Supprimer une picture
    // #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    // public function delete(int $id): JsonResponse
    // {
    //     // Récupérer l'picture depuis la base de données
    //     $picture = $this->repository->find($id);

    //     if (!$picture) {
    //         return new JsonResponse(['error' => 'picture not found'], Response::HTTP_NOT_FOUND);
    //     }

    //     // Construire le chemin absolu du fichier
    //     $filePath = $this->getParameter('kernel.project_dir') . '/public' . $picture->getFilePath();

    //     // Vérifier si le fichier existe et le supprimer
    //     if (file_exists($filePath)) {
    //         unlink($filePath);
    //     }

    //     // Supprimer l'picture de la base de données
    //     $this->manager->remove($picture);
    //     $this->manager->flush();

    //     return new JsonResponse(['message' => 'picture deleted successfully'], Response::HTTP_OK);
    // }
}

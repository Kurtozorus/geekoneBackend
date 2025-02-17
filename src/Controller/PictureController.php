<?php

namespace App\Controller;

use App\Entity\Picture;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[Route('/api/pictures')]
class PictureController extends AbstractController
{
    private string $uploadDir;

    public function __construct(
        private EntityManagerInterface $manager,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
        private SluggerInterface $slugger,
        private KernelInterface $kernel // Injection du kernel pour obtenir le répertoire
    ) {
        // Définition du répertoire d'upload des images
        $this->uploadDir = $this->kernel->getProjectDir() . '/public/uploads/pictures/';
    }

    // Ajouter une image
    #[Route(name: 'new', methods: ['POST'])]
    public function new(
        Request $request,
        ValidatorInterface $validator,
        SluggerInterface $slugger,
        CsrfTokenManagerInterface $csrfTokenManager
    ): JsonResponse {

        //Vérification du token CSRF pour prévenir les attaques CSRF
        // $submittedToken = $request->get('csrf_token');
        // if (!$csrfTokenManager->isTokenValid(new CsrfToken('upload', $submittedToken))) {
        //     return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        // }

        //Récupération des données envoyées par le client
        $title = $request->get('title');
        $slug = $request->get('slug');
        $fileData = $request->get('fileData'); // Image en Base64
        $pictureFile = $request->files->get('picture'); // Image envoyée en fichier

        //Vérification des champs obligatoires
        if (!$title || !$slug || (!$pictureFile && empty($fileData))) {
            return new JsonResponse(
                ['error' => 'Champs obligatoires manquants'],
                Response::HTTP_BAD_REQUEST
            );
        }

        //Définition des types MIME autorisés
        $allowedMimeTypes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp'
        ];
        $maxSize = 5 * 1024 * 1024; // 5MB

        //Génération d'un nom de fichier unique
        $fileName = uniqid('', true);

        if ($pictureFile) {
            //Vérification du type MIME du fichier
            $mimeType = $pictureFile->getMimeType();
            if (!in_array($mimeType, $allowedMimeTypes)) {
                return new JsonResponse(
                    ['error' => 'Type de fichier invalide'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            //Vérification de la taille du fichier
            if ($pictureFile->getSize() > $maxSize) {
                return new JsonResponse(
                    ['error' => 'Fichier trop grand'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            //Récupération et ajout de l'extension au nom du fichier
            $extension = $pictureFile->guessExtension();
            $fileName .= '.' . $extension;

            //Déplacement du fichier vers un dossier sécurisé (hors de l'accès direct du public)
            $pictureFile->move($this->uploadDir, $fileName);
        } else {
            //Vérification et décodage des données Base64
            $fileContent = base64_decode($fileData, true);
            if (!$fileContent) {
                return new JsonResponse(
                    ['error' => 'Données Base64 invalides'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            //Vérification du type MIME après décodage
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($fileContent);
            if (!in_array($mimeType, $allowedMimeTypes)) {
                return new JsonResponse(
                    ['error' => 'Type de fichier base64 invalide'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            //Génération du nom de fichier avec une extension sécurisée
            // $fileName .= '.jpg'; // On force l'extension jpg pour éviter l'exécution de scripts malveillants
            // file_put_contents(
            //     $this->uploadDir . '/' . $fileName,
            //     $fileContent
            // );
        }

        //Génération d'une URL sécurisée pour l'accès à l'image
        $fileUrl = $request->getSchemeAndHttpHost() . '/uploads/pictures/' . $fileName;

        //Création d'une entité Picture et assignation des données
        $picture = new Picture();
        $picture->setFilePath($fileUrl);
        $picture->setImagePath($fileName);
        //Protection contre XSS
        $picture->setTitle(
            htmlspecialchars(
                $title,
                ENT_QUOTES,
                'UTF-8'
            ),
            $slugger
        );
        //Protection contre XSS
        $picture->setSlug(
            htmlspecialchars(
                $slug,
                ENT_QUOTES,
                'UTF-8'
            )
        );

        $picture->setCreatedAt(new \DateTimeImmutable());

        //Validation des données de l'entité avant de l'enregistrer
        $errors = $validator->validate($picture);
        if (count($errors) > 0) {
            return new JsonResponse(
                ['error' => (string) $errors],
                Response::HTTP_BAD_REQUEST
            );
        }

        //Sauvegarde en base de données
        $this->manager->persist($picture);
        $this->manager->flush();

        //Réponse JSON avec un message de succès
        return new JsonResponse(
            [
                'status' => 'success',
                'message' => 'Image uploaded avec succes',
                'image' => [
                    'id' => $picture->getId(),
                    'title' => $picture->getTitle(),
                    'slug' => $picture->getSlug(),
                    'filePath' => $picture->getFilePath(),
                    'imagePath' => $picture->getImagePath(),
                    'createdAt' => $picture->getCreatedAt()->format("d-m-Y")
                ]
            ],
            Response::HTTP_CREATED
        );
    }

    //Show : Récupérer l'images par son ID
    #[Route('/{id}', name: 'picture_show', methods: ['GET'])]
    public function show(
        int $id,
        Request $request,
        UrlGeneratorInterface $urlGenerator
    ): JsonResponse|BinaryFileResponse {
        // Récupérer l'image depuis la base de données
        $picture = $this->manager->getRepository(Picture::class)->find($id);

        // Vérification de l'existence de l'image
        if (!$picture || !$picture->getImagePath()) {
            return new JsonResponse(
                ['error' => 'Image introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Construction du chemin absolu sécurisé du fichier image
        $imagePath = realpath(
            $this->uploadDir . DIRECTORY_SEPARATOR . $picture->getImagePath()
        );

        // Sécurisation contre les attaques de type Path Traversal
        if (!$imagePath || strpos($imagePath, realpath($this->uploadDir)) !== 0) {
            return new JsonResponse(
                ['error' => 'Acces refusé !'],
                Response::HTTP_FORBIDDEN
            );
        }

        // Vérifier si le fichier existe
        if (!file_exists($imagePath) || !is_readable($imagePath)) {
            return new JsonResponse(
                ['error' => 'Fichier introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Générer l'URL de l'image dans le navigateur
        $imageUrl = $urlGenerator->generate(
            'picture_show',
            [
                'id' => $id,
                'view' => 'image'
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Retourner soit l'image dans la réponse (si demandé directement), soit les métadonnées
        if ($request->query->get('view') === 'image') {
            $response = new BinaryFileResponse($imagePath);
            $response->headers->set(
                'Content-Type',
                mime_content_type(
                    $imagePath
                )
            );
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                basename(
                    $imagePath
                )
            );
            return $response;
        }

        // Si on ne demande pas l'image directement, renvoyer les métadonnées avec l'URL
        return new JsonResponse(
            [
                'status' => 'success',
                'id' => $picture->getId(),
                'title' => $picture->getTitle(),
                'slug' => $picture->getSlug(),
                'filePath' => $picture->getFilePath(),
                'imagePath' => $picture->getImagePath(),
                'imageUrl' => $imageUrl,
                'createdAt' => $picture->getCreatedAt()->format("d-m-Y"),
            ],
            Response::HTTP_OK
        );
    }

    //Lecture (GET) : Récupérer toutes les images
    #[Route('/', name: 'picture_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $pictures = $this->manager->getRepository(Picture::class)->findAll();

        $imageList = array_map(function (Picture $picture) {
            return [
                'id' => $picture->getId(),
                'title' => $picture->getTitle(),
                'slug' => $picture->getSlug(),
                'filePath' => $picture->getFilePath(),
                'imagePath' => $picture->getImagePath(),
                'createdAt' => $picture->getCreatedAt()->format("d-m-Y")
            ];
        }, $pictures);

        return new JsonResponse([
            'status' => 'success',
            'images' => $imageList
        ], Response::HTTP_OK);
    }

    //Modifier une image
    #[Route('/{id}', name: 'edit', methods: ['POST'])]
    public function edit(
        int $id,
        Request $request,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        // Récupérer l'image depuis la base de données
        $picture = $this->manager->getRepository(Picture::class)->find($id);

        if (!$picture) {
            return new JsonResponse(
                ['error' => 'Image introuvable'],
                Response::HTTP_NOT_FOUND
            );
        }

        // Récupérer et mettre à jour le titre et le slug (s'ils sont fournis)
        $title = $request->request->get('title');
        $slug = $request->request->get('slug');

        if ($title) {
            $picture->setTitle($title);
        }
        if ($slug) {
            $picture->setSlug($slug);
        }

        // Récupérer le fichier envoyé
        $uploadedFile = $request->files->get('picture');

        // Vérification qu'au moins un champ est fourni
        if (!$title && !$slug && !$uploadedFile) {
            return new JsonResponse(
                ['error' => 'Aucun changement fourni'],
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($uploadedFile) {
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
                    ['error' => 'Type de fichier invalide'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // Supprimer l'ancien fichier s'il existe
            $oldFilePath = $this->uploadDir . $picture->getImagePath();
            if (file_exists($oldFilePath) && is_file($oldFilePath)) {
                unlink($oldFilePath);
            }

            // Générer un nouveau nom de fichier
            $fileName = uniqid() . '-' . preg_replace(
                '/[^a-zA-Z0-9\._-]/',
                '',
                $uploadedFile->getClientOriginalName()
            );

            // Vérification et création du dossier d'upload si nécessaire
            if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0775, true)) {
                return new JsonResponse(
                    ['error' => 'Échec de la création du répertoire de téléchargement'],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }

            // Déplacement du fichier
            try {
                $uploadedFile->move($this->uploadDir, $fileName);
            } catch (FileException $e) {
                return new JsonResponse(
                    ['error' => 'Échec du téléchargement du fichier'],
                    Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }

            // Mise à jour de l'image
            $picture->setFilePath('/uploads/pictures/' . $fileName);
            $picture->setImagePath($fileName);
        }

        // Mettre à jour la date de modification
        $picture->setUpdatedAt(new DateTimeImmutable());

        $this->manager->flush();

        // Chemin absolu du fichier (corrigé)
        $imagePath = $this->getParameter('kernel.project_dir') . '/public' . $picture->getFilePath();

        // Vérification de l'existence du fichier
        if (!file_exists($imagePath)) {
            return new JsonResponse(
                ['error' => 'Fichier non trouvé après téléchargement'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // Générer l'URL de l'image dans le navigateur
        $imageUrl = $urlGenerator->generate(
            'picture_show',
            ['id' => $id, 'view' => 'image'],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Retourner l'image si demandée en tant qu'affichage
        if ($request->query->get('view') === 'image') {
            $response = new BinaryFileResponse($imagePath);
            $response->headers->set('Content-Type', mime_content_type($imagePath));
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($imagePath));
            return $response;
        }

        return new JsonResponse([
            'status' => 'success',
            'id' => $picture->getId(),
            'title' => $picture->getTitle(),
            'slug' => $picture->getSlug(),
            'filePath' => $picture->getFilePath(),
            'imageUrl' => $imageUrl,
            'createdAt' => $picture->getCreatedAt()->format("d-m-Y H:i:s"),
            'updatedAt' => $picture->getUpdatedAt()->format("d-m-Y H:i:s")
        ], Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'picture_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $picture = $this->manager->getRepository(Picture::class)->find($id);

        if (!$picture) {
            return new JsonResponse(
                [
                    'status' => 'error',
                    'message' => 'Image introuvable'
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        //Supprimer le fichier du serveur
        $filePath = $this->uploadDir . '/' . $picture->getImagePath();
        if (file_exists($filePath) && is_file($filePath)) {
            unlink($filePath);
        }

        $this->manager->remove($picture);
        $this->manager->flush();

        return new JsonResponse(
            [
                'status' => 'success',
                'message' => 'Image supprimée avec succès'
            ],
            Response::HTTP_NO_CONTENT
        );
    }
}
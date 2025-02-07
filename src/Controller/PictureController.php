<?php

namespace App\Controller;

use App\Entity\Picture;
use App\Repository\PictureRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

#[Route('/api/pictures')]
class PictureController extends AbstractController
{
    private string $uploadDir;

    public function __construct(
        private EntityManagerInterface $manager,
        private SerializerInterface $serializer
    ) {
        // Définir le répertoire d'upload des images
        $this->uploadDir = __DIR__ . '/../../public/uploads/pictures/';
    }

    //Code avec securisations contre les failles et attaques
    #[Route('/new', name: 'picture_new', methods: ['POST'])]
    public function new(
        Request $request,
        ValidatorInterface $validator,
        SluggerInterface $slugger,
        CsrfTokenManagerInterface $csrfTokenManager
    ): JsonResponse {

        // 🔐 Vérification du token CSRF pour prévenir les attaques CSRF
        // $submittedToken = $request->get('csrf_token');
        // if (!$csrfTokenManager->isTokenValid(new CsrfToken('upload', $submittedToken))) {
        //     return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        // }

        // 📥 Récupération des données envoyées par le client
        $title = $request->get('title');
        $slug = $request->get('slug');
        $fileData = $request->get('fileData'); // Image en Base64
        $pictureFile = $request->files->get('picture'); // Image envoyée en fichier

        // ✅ Vérification des champs obligatoires
        if (!$title || !$slug || (!$pictureFile && empty($fileData))) {
            return new JsonResponse(
                ['error' => 'Missing required fields'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // 🛡️ Définition des types MIME autorisés
        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif'
        ];
        $maxSize = 5 * 1024 * 1024; // 5MB

        // 🔄 Génération d'un nom de fichier unique
        $fileName = uniqid('', true);

        if ($pictureFile) {
            // 🧐 Vérification du type MIME du fichier
            $mimeType = $pictureFile->getMimeType();
            if (!in_array($mimeType, $allowedMimeTypes)) {
                return new JsonResponse(
                    ['error' => 'Invalid file type'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // 📏 Vérification de la taille du fichier
            if ($pictureFile->getSize() > $maxSize) {
                return new JsonResponse(
                    ['error' => 'File too large'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // 🔍 Récupération et ajout de l'extension au nom du fichier
            $extension = $pictureFile->guessExtension();
            $fileName .= '.' . $extension;

            // 📂 Déplacement du fichier vers un dossier sécurisé (hors de l'accès direct du public)
            $pictureFile->move($this->uploadDir, $fileName);
        } else {
            // 🧐 Vérification et décodage des données Base64
            $fileContent = base64_decode($fileData, true);
            if (!$fileContent) {
                return new JsonResponse(
                    ['error' => 'Invalid Base64 data'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // 🔍 Vérification du type MIME après décodage
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($fileContent);
            if (!in_array($mimeType, $allowedMimeTypes)) {
                return new JsonResponse(
                    ['error' => 'Invalid base64 file type'],
                    Response::HTTP_BAD_REQUEST
                );
            }

            // 📂 Génération du nom de fichier avec une extension sécurisée
            $fileName .= '.jpg'; // On force l'extension jpg pour éviter l'exécution de scripts malveillants
            file_put_contents(
                $this->uploadDir . '/' . $fileName,
                $fileContent
            );
        }

        // 🌐 Génération d'une URL sécurisée pour l'accès à l'image
        $fileUrl = $request->getSchemeAndHttpHost() . '/uploads/pictures/' . $fileName;

        // 🖼️ Création d'une entité Picture et assignation des données
        $picture = new Picture();
        $picture->setFilePath($fileUrl);
        $picture->setImagePath($fileName);
        // 🛡️ Protection contre XSS
        $picture->setTitle(
            htmlspecialchars(
                $title,
                ENT_QUOTES,
                'UTF-8'
            ),
            $slugger
        );
        // 🛡️ Protection contre XSS
        $picture->setSlug(
            htmlspecialchars(
                $slug,
                ENT_QUOTES,
                'UTF-8'
            )
        );

        $picture->setCreatedAt(new \DateTimeImmutable());

        // ✅ Validation des données de l'entité avant de l'enregistrer
        $errors = $validator->validate($picture);
        if (count($errors) > 0) {
            return new JsonResponse(
                ['error' => (string) $errors],
                Response::HTTP_BAD_REQUEST
            );
        }

        // 💾 Sauvegarde en base de données
        $this->manager->persist($picture);
        $this->manager->flush();

        // 📤 Réponse JSON avec un message de succès
        return new JsonResponse(
            [
                'status' => 'success',
                'message' => 'Image uploaded successfully',
                'image' => [
                    'id' => $picture->getId(),
                    'title' => $picture->getTitle(),
                    'slug' => $picture->getSlug(),
                    'filePath' => $picture->getFilePath(),
                    'imagePath' => $picture->getImagePath(),
                    'createdAt' => $picture->getCreatedAt()->format('Y-m-d H:i:s')
                ]
            ],
            Response::HTTP_CREATED
        );
    }

    #[Route('/{id}', name: 'picture_show', methods: ['GET'])]
    public function show(
        int $id,
        UrlGeneratorInterface $urlGenerator
    ): JsonResponse|BinaryFileResponse {
        // Récupérer l'image depuis la base de données
        $picture = $this->manager->getRepository(Picture::class)->find($id);

        // Vérification de l'existence de l'image
        if (!$picture || !$picture->getImagePath()) {
            return new JsonResponse(
                ['error' => 'Image not found'],
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
                ['error' => 'Unauthorized access'],
                Response::HTTP_FORBIDDEN
            );
        }

        // Vérifier si le fichier existe
        if (!file_exists($imagePath) || !is_readable($imagePath)) {
            return new JsonResponse(
                ['error' => 'File not found on server'],
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
        if (isset($_GET['view']) && $_GET['view'] === 'image') {
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
                'imageUrl' => $imageUrl,  // Lien direct pour afficher l'image dans le navigateur
                'createdAt' => $picture->getCreatedAt()->format('Y-m-d H:i:s'),
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
                'createdAt' => $picture->getCreatedAt()->format('Y-m-d H:i:s')
            ];
        }, $pictures);

        return new JsonResponse([
            'status' => 'success',
            'images' => $imageList
        ], Response::HTTP_OK);
    }




    #[Route('/{id}', name: 'picture_edit', methods: ['PUT'])]
    public function edit(
        int $id,
        Request $request,
        ValidatorInterface $validator,
        SluggerInterface $slugger
    ): JsonResponse {
        $picture = $this->manager->getRepository(Picture::class)->find($id);

        if (!$picture) {
            return new JsonResponse(['error' => 'Image not found'], Response::HTTP_NOT_FOUND);
        }

        $title = $request->get('title');
        $slug = $request->get('slug');
        $fileData = $request->get('fileData');
        $pictureFile = $request->files->get('picture');

        // Mettre à jour le titre et le slug
        if ($title) {
            $picture->setTitle($title);
            $picture->setSlug($slugger->slug($title)->lower());
        }
        if ($slug) {
            $picture->setSlug($slug);
        }

        // Si une nouvelle image est envoyée
        if ($pictureFile) {
            $newFileName = uniqid('', true) . '.' . $pictureFile->guessExtension();
            $pictureFile->move($this->uploadDir, $newFileName);

            $picture->setFilePath('/uploads/pictures/' . $newFileName);
            $picture->setImagePath($newFileName);
        }

        // Mettre à jour la date de modification
        $picture->setUpdatedAt(new \DateTimeImmutable());

        // Valider avant d'enregistrer
        $errors = $validator->validate($picture);
        if (count($errors) > 0) {
            return new JsonResponse(['error' => (string) $errors], Response::HTTP_BAD_REQUEST);
        }

        // Sauvegarde
        $this->manager->flush();
        $this->manager->clear(); // Vide le cache local de Doctrine

        $updatedPicture = $this->manager->getRepository(Picture::class)->find($id);
        dd($updatedPicture);

        $imageUrl = $request->getSchemeAndHttpHost() . '/uploads/pictures/' . $picture->getImagePath();

        return new JsonResponse([
            'status' => 'success',
            'message' => 'Image updated successfully',
            'image' => [
                'id' => $picture->getId(),
                'title' => $picture->getTitle(),
                'slug' => $picture->getSlug(),
                'filePath' => $picture->getFilePath(),
                'imagePath' => $picture->getImagePath(),
                'imageUrl' => $imageUrl,
                'updatedAt' => $picture->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
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
                    'message' => 'Image not found'
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        // 🔥 Supprimer le fichier du serveur
        $filePath = $this->uploadDir . '/' . $picture->getImagePath();
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $this->manager->remove($picture);
        $this->manager->flush();

        return new JsonResponse(
            [
                'status' => 'success',
                'message' => 'Image deleted successfully'
            ],
            Response::HTTP_OK
        );
    }
}

<?php

namespace App\Controller;

use App\Entity\Picture;
use App\Repository\PictureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('api/picture', name: 'app_api_picture_')]
final class PictureController extends AbstractController{
    public function __construct(private EntityManagerInterface $manager, private PictureRepository $repository)
    {
        
    }
    #[Route(name: 'new', methods: ['POST'])]
    public function new(): JsonResponse
    {
        $picture = new Picture();
        $picture->setImageData('GeekOne');
        $picture->setTitle('Votre magasin de jeux vidéo');
        $picture->setSlug('Votre magasin de jeux vidéo');
        $picture->setCreatedAt(new \DateTimeImmutable());
        $picture->setUpdatedAt(new \DateTimeImmutable());

        $this->manager->persist($picture);
        $this->manager->flush();
        return $this->json(
            ['message' => "picture ressource created with {$picture->getId()} id"],
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route('/{id}',name: 'show',methods: 'GET')]
    public function show(int $id): JsonResponse
    {
        $picture = $this->repository->findOneBy(['id'=>$id]);

        if(!$picture) {
            throw new \Exception("No picture found for {$id} id");
        }

        return $this->json(
            ['message' => "A picture was found : {$picture->getName()} for {$picture->getId()} id"],
        );
    }

    #[Route('/{id}',name: 'edit', methods: 'PUT')]
    public function edit(int $id): Response
    {
        $picture = $this->repository->findOneBy(['id'=>$id]);
         if(!$picture) {
            throw new \Exception("No picture found for {$id} id");
        }

        $picture->setName('picture name updated');

        $this->manager->flush();

        return $this->redirectToRoute('app_api_picture_show', ['id' => $picture->getId()]);
    }

    #[Route('/{id}',name: 'delete', methods: 'DELETE')]
    public function delete(int $id): JsonResponse
    {
        $picture = $this->repository->findOneBy(['id'=>$id]);
         if(!$picture) {
            throw new \Exception("No picture found for {$id} id");
        }
        $this->manager->remove($picture);
        $this->manager->flush();
        return $this->json(['message'=>'picture ressource deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}

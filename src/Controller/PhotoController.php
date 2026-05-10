<?php

namespace App\Controller;

use App\Entity\Photo;
use App\Repository\PhotoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/photo')]
class PhotoController extends AbstractController
{
    #[Route('/', name: 'photo_index')]
    public function index(PhotoRepository $photoRepository): Response
    {
        $photos = $photoRepository->findBy([], ['createdAt' => 'DESC'], 50);

        return $this->render('photo/index.html.twig', [
            'photos' => $photos,
        ]);
    }

    #[Route('/upload', name: 'photo_upload')]
    #[IsGranted('ROLE_USER')]
    public function upload(): Response
    {
        return $this->render('photo/upload.html.twig');
    }

    #[Route('/api/upload', name: 'photo_api_upload', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function apiUpload(Request $request, SluggerInterface $slugger, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->files->get('file');

        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded'], 400);
        }

        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $slugger->slug($originalFilename);
        $newFilename = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();

        try {
            $file->move(
                $this->getParameter('photos_directory'),
                $newFilename
            );
        } catch (FileException $e) {
            return new JsonResponse(['error' => 'Upload failed'], 500);
        }

        $photo = new Photo();
        $photo->setFilename($newFilename);
        $photo->setOriginalFilename($file->getClientOriginalName());
        $photo->setFileSize($file->getSize());
        $photo->setFileType($file->getMimeType());
        $photo->setAuthor($this->getUser());

        // Extract image dimensions
        $filepath = $this->getParameter('photos_directory') . '/' . $newFilename;
        if ($imageInfo = @getimagesize($filepath)) {
            $photo->setWidth($imageInfo[0]);
            $photo->setHeight($imageInfo[1]);
        }

        // Extract EXIF data
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($filepath);
            if ($exif) {
                $photo->setExif($exif);
                if (isset($exif['DateTimeOriginal'])) {
                    $photo->setTakenAt(new \DateTime($exif['DateTimeOriginal']));
                }
            }
        }

        $entityManager->persist($photo);
        $entityManager->flush();

        return new JsonResponse($photo);
    }

    #[Route('/{id}', name: 'photo_show')]
    public function show(Photo $photo): Response
    {
        return $this->render('photo/show.html.twig', [
            'photo' => $photo,
        ]);
    }

    #[Route('/{id}/edit', name: 'photo_edit')]
    #[IsGranted('ROLE_USER')]
    public function edit(Photo $photo, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($photo->getAuthor() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(\App\Form\PhotoType::class, $photo);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Photo updated successfully!');

            return $this->redirectToRoute('photo_show', ['id' => $photo->getId()]);
        }

        return $this->render('photo/edit.html.twig', [
            'photo' => $photo,
            'form' => $form,
        ]);
    }
}

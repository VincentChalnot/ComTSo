<?php

namespace App\Controller;

use App\Entity\Photo;
use App\Form\PhotoType;
use App\Pagination\Pager;
use App\Repository\PhotoRepository;
use App\Service\ImageProcessingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/** Photos are private: files live outside the web root and are only ever served here, to logged-in members. */
class PhotoController extends AbstractController
{
    public const PHOTOS_PER_PAGE = 60;

    #[Route('/photos', name: 'photo_index')]
    public function index(Request $request, PhotoRepository $photos): Response
    {
        $pager = new Pager($photos->count([]), self::PHOTOS_PER_PAGE, $request->query->getInt('page', 1));

        return $this->render('photo/index.html.twig', [
            'photos' => $photos->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], $pager->perPage, $pager->offset()),
            'pager' => $pager,
        ]);
    }

    #[Route('/photos/source/{id}', name: 'photo_source', requirements: ['id' => '\d+'])]
    public function source(Request $request, Photo $photo, ImageProcessingService $images): Response
    {
        $path = $images->originalPath($photo->getFilename());
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $photo->getFileType() ?: 'application/octet-stream');
        $response->setContentDisposition(
            $request->query->getBoolean('download') ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            $photo->getOriginalFilename() ?: $photo->getFilename(),
            // ASCII fallback for exotic file names
            preg_replace('/[^A-Za-z0-9._-]/', '_', $photo->getFilename()),
        );

        return $this->cacheable($response);
    }

    #[Route('/photos/{id}/{variant}', name: 'photo_variant', requirements: ['id' => '\d+', 'variant' => 'avatar|thumb|medium'])]
    public function variant(Photo $photo, string $variant, ImageProcessingService $images): Response
    {
        $path = $images->thumbnailPath($photo->getFilename(), $variant);
        if (null === $path) {
            throw $this->createNotFoundException();
        }

        return $this->cacheable(new BinaryFileResponse($path));
    }

    #[Route('/photos/{id}', name: 'photo_show', requirements: ['id' => '\d+'])]
    public function show(Photo $photo): Response
    {
        return $this->render('photo/show.html.twig', ['photo' => $photo]);
    }

    #[Route('/photos/{id}/edit', name: 'photo_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, Photo $photo, EntityManagerInterface $em): Response
    {
        if ($photo->getAuthor() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(PhotoType::class, $photo);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'La photo a été modifiée.');

            return $this->redirectToRoute('photo_show', ['id' => $photo->getId()]);
        }

        return $this->render('photo/edit.html.twig', ['photo' => $photo, 'form' => $form]);
    }

    /** Image content never changes for a given id: let the browser keep it (privately) for a year. */
    private function cacheable(BinaryFileResponse $response): BinaryFileResponse
    {
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');
        $response->setAutoEtag();

        return $response;
    }
}

<?php

namespace App\Controller;

use App\Entity\Photo;
use App\Entity\User;
use App\Form\PhotoType;
use App\Pagination\Pager;
use App\Photo\PhotoRotator;
use App\Repository\CommentPhotoRepository;
use App\Repository\PhotoLikeRepository;
use App\Repository\PhotoRepository;
use App\Service\ImageProcessingService;
use App\Service\PhotoUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Photos are private: files live outside the web root and are only ever served here, to logged-in members. */
class PhotoController extends AbstractController
{
    public const PHOTOS_PER_PAGE = 60;
    public const LIKERS_SHOWN = 50;

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
        $name = self::downloadName($photo);
        $response->setContentDisposition(
            $request->query->getBoolean('download') ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            $name,
            // ASCII fallback for exotic file names
            preg_replace('/[^A-Za-z0-9._-]/', '_', $name),
        );

        return $this->cacheable($response);
    }

    #[Route('/photos/{id}/{variant}', name: 'photo_variant', requirements: ['id' => '\d+', 'variant' => 'avatar|thumb|medium|hero'])]
    public function variant(Photo $photo, string $variant, ImageProcessingService $images): Response
    {
        $path = $images->thumbnailPath($photo->getFilename(), $variant);
        if (null === $path) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', ImageProcessingService::VARIANT_MIME_TYPE);

        return $this->cacheable($response);
    }

    #[Route('/photos/{id}', name: 'photo_show', requirements: ['id' => '\d+'])]
    public function show(Photo $photo, #[CurrentUser] User $user, CommentPhotoRepository $commentPhotos, PhotoLikeRepository $likes): Response
    {
        $likers = $likes->likers($photo, self::LIKERS_SHOWN);

        return $this->render('photo/show.html.twig', [
            'photo' => $photo,
            'messageUsages' => $commentPhotos->findUsages($photo),
            'likers' => $likers,
            'likeCount' => $likes->countFor($photo),
            'liked' => $likes->isLikedBy($photo, $user),
            // Where a photo was taken is for its owner (and moderators) only: members' homes would leak otherwise.
            'canSeeGps' => $this->canManage($photo),
        ]);
    }

    /** State of the like button of a photo (the lightbox loads it when it shows a photo): liked by me?, how many likes, CSRF token of the toggle. */
    #[Route('/photos/{id}/like', name: 'photo_like_state', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function likeState(Photo $photo, #[CurrentUser] User $user, PhotoLikeRepository $likes, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $response = new JsonResponse([
            'liked' => $likes->isLikedBy($photo, $user),
            'count' => $likes->countFor($photo),
            'token' => $csrf->getToken(self::likeTokenId($photo))->getValue(),
        ]);

        return $response->setPrivate()->setMaxAge(0);
    }

    /** Like / unlike (toggle). Any member may like any photo, their own included. JSON `{liked, count}` for fetch(), redirect otherwise. */
    #[Route('/photos/{id}/like', name: 'photo_like', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function like(Request $request, Photo $photo, #[CurrentUser] User $user, PhotoLikeRepository $likes): Response
    {
        if (!$this->isCsrfTokenValid(self::likeTokenId($photo), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $liked = $likes->toggle($photo, $user);

        if ($request->isXmlHttpRequest() || str_contains($request->headers->get('Accept', ''), 'json')) {
            return new JsonResponse(['liked' => $liked, 'count' => $likes->countFor($photo)]);
        }

        return $this->redirectToRoute('photo_show', ['id' => $photo->getId(), '_fragment' => 'likes']);
    }

    #[Route('/photos/{id}/edit', name: 'photo_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, Photo $photo, EntityManagerInterface $em): Response
    {
        $this->denyUnlessCanManage($photo);

        $form = $this->createForm(PhotoType::class, $photo);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'La photo a été modifiée.');

            return $this->redirectToRoute('photo_show', ['id' => $photo->getId()]);
        }

        return $this->render('photo/edit.html.twig', ['photo' => $photo, 'form' => $form]);
    }

    /**
     * Turns the picture a quarter turn (`direction` = `left` or `right`), for its owner and the administrators. The stored
     * WebP is re-encoded by {@see PhotoRotator}; an animated GIF is refused.
     */
    #[Route('/photos/{id}/rotate', name: 'photo_rotate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rotate(Request $request, Photo $photo, PhotoRotator $rotator): Response
    {
        $this->denyUnlessCanManage($photo);
        if (!$this->isCsrfTokenValid('rotate-photo-'.$photo->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $direction = $request->getPayload()->getString('direction');
        if (!\in_array($direction, ['left', 'right'], true)) {
            throw new BadRequestHttpException('Unknown rotation direction.');
        }

        try {
            $rotator->rotate($photo, 'right' === $direction);
            $this->addFlash('success', 'La photo a été pivotée vers la '.('right' === $direction ? 'droite' : 'gauche').'.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('photo_show', ['id' => $photo->getId()]);
    }

    /**
     * Deletes a photo and its files. An administrator may delete any photo: it disappears from every topic and message
     * that used it (and stops being anybody's avatar). The author may only delete a photo nothing uses any more.
     */
    #[Route('/photos/{id}/delete', name: 'photo_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Photo $photo, EntityManagerInterface $em, PhotoRepository $photos, PhotoUploader $uploader): Response
    {
        $this->denyUnlessCanManage($photo);
        if (!$this->isCsrfTokenValid('delete-photo-'.$photo->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $admin = $this->isGranted('ROLE_ADMIN');
        if (!$admin && $photos->isReferenced($photo)) {
            $this->addFlash('error', 'Cette photo est utilisée dans un sujet, un message ou comme avatar : retirez-la d\'abord de ces endroits.');

            return $this->redirectToRoute('photo_show', ['id' => $photo->getId()]);
        }

        $filename = (string) $photo->getFilename();
        $em->createQuery('UPDATE '.User::class.' u SET u.avatar = NULL WHERE u.avatar = :photo')->setParameter('photo', $photo)->execute();
        // Links to topics, messages and likes go away with the row (ON DELETE CASCADE).
        $em->createQuery('DELETE FROM '.Photo::class.' p WHERE p.id = :id')->setParameter('id', $photo->getId())->execute();
        $uploader->deleteFiles($filename);
        $this->addFlash('success', 'La photo a été supprimée.');

        return $this->redirectToRoute('photo_index');
    }

    public static function likeTokenId(Photo $photo): string
    {
        return 'like-photo-'.$photo->getId();
    }

    /** Download name: what the member called the file, with the extension of what is stored (WebP). */
    private static function downloadName(Photo $photo): string
    {
        $base = pathinfo((string) ($photo->getOriginalFilename() ?: $photo->getFilename()), \PATHINFO_FILENAME);

        return ('' !== $base ? $base : 'photo').'.'.pathinfo((string) $photo->getFilename(), \PATHINFO_EXTENSION);
    }

    private function canManage(Photo $photo): bool
    {
        return $this->isGranted('ROLE_ADMIN') || (null !== $photo->getAuthor() && $photo->getAuthor() === $this->getUser());
    }

    private function denyUnlessCanManage(Photo $photo): void
    {
        if (!$this->canManage($photo)) {
            throw $this->createAccessDeniedException();
        }
    }

    /** Image content never changes for a given id: let the browser keep it (privately) for a year. */
    private function cacheable(BinaryFileResponse $response): BinaryFileResponse
    {
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->headers->addCacheControlDirective('immutable');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setAutoEtag();

        return $response;
    }
}

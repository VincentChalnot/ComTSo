<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Photo;
use ComTSo\ForumBundle\Form\Type\PhotoType;
use ComTSo\ForumBundle\Lib\Utils;
use DateInterval;
use DateTime;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PhotoController extends BaseController
{
    /**
     * @Template()
     */
    public function showAction(Photo $photo)
    {
        $this->viewParameters['photo'] = $photo;
        $this->viewParameters['title'] = (string) $photo;

        return $this->viewParameters;
    }
    
    /**
     * @Template()
     */
    public function editAction(Request $request, Photo $photo)
    {
        $this->viewParameters['photo'] = $photo;
        $this->viewParameters['title'] = (string) $photo;
        
        $form = $this->createForm(new PhotoType, $photo, ['label' => 'Édition de la photo']);

        if ($this->getRequest()->isMethod('POST')) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $em = $this->getManager();
                $em->persist($photo);
                $em->flush();

                $this->addFlashMsg('success', 'Topic mis à jour');
                return $this->redirect($this->generateUrl('comtso_photo_show', $photo->getRoutingParameters()));
            }
        }

        $this->viewParameters['form'] = $form->createView();

        return $this->viewParameters;
    }

    public function sourceAction(Request $request, Photo $photo)
    {
        $filePath = "{$this->getConfigParameter('comtso.photo_dir')}/originals/{$photo->getFilename()}";

        return $this->createImageResponse($request, $filePath, $photo, $this->getRequest()->get('download') ? 'attachment' : 'inline');
    }

    public function sourceCacheAction(Request $request, Photo $photo, $filter)
    {
        $filePath = "{$this->getConfigParameter('comtso.photo_dir')}/cache/{$filter}/{$photo->getFilename()}";
        if (!file_exists($filePath)) {
            return $this->container->get('liip_imagine.controller')->filterAction($request, $photo->getFilename(), $filter);
        }

        return $this->createImageResponse($request, $filePath, $photo);
    }

    /**
     * @Template()
     */
    public function listAction()
    {
        $this->viewParameters['photos'] = $this->getRepository('Photo')->findAll();
        $this->viewParameters['title'] = 'Photos';

        return $this->viewParameters;
    }

    /**
     * @param  Request      $request
     * @return JsonResponse
     */
    public function updateAction(Request $request, Photo $photo)
    {
        $photo->setTitle($request->request->get('title'));
        $this->getManager()->persist($photo);
        $this->getManager()->flush();

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * @Template()
     */
    public function browserAction(Request $request)
    {
        $qb = $this->getRepository('Photo')->createQueryBuilder('e');
        $photos = $this->createPager($qb, $request, 'createdAt', 'd')->initialize();
        $this->viewParameters['photos'] = $photos;
        $this->viewParameters['title'] = 'Photo Browser';

        return $this->render('ComTSoForumBundle:Photo:selector.html.twig', $this->viewParameters);
    }

    /**
     * @Template()
     */
    public function uploaderAction(Request $request)
    {
        $this->viewParameters['title'] = 'Photo Uploader';
        $this->viewParameters['targetWidget'] = $request->get('widget');
        if ($request->isXmlHttpRequest()) {
            return $this->render('ComTSoForumBundle:Photo:uploader.html.twig', $this->viewParameters);
        }

        return $this->viewParameters;
    }

    /**
     * @Template()
     */
    public function widgetAction(Request $request, Photo $photo)
    {
        $this->viewParameters['photo'] = $photo;

        return $this->viewParameters;
    }

    protected function createImageResponse(Request $request, $filePath, Photo $photo = null, $contentDisposition = 'inline')
    {
        $response = new BinaryFileResponse($filePath, 200, [], true, $contentDisposition, false, true);
        $date = new DateTime();
        $date->add(new DateInterval('P1Y'));
        $date->setTime(0, 0, 0);

        if ($response->isNotModified($request)) {
            $response = new Response(null, Response::HTTP_NOT_MODIFIED);
            $response->setPublic();
            $response->setExpires($date);

            return $response;
        }

        $response->setExpires($date);

        if ($photo) {
            $filename = $photo->getTitle() ? $photo->getTitle().'.'.$photo->getFileType() : $photo->getOriginalFilename();
            $response->setContentDisposition($this->getRequest()->get('download') ? 'attachment' : 'inline', Utils::slugify($filename));
        }

        return $response;
    }
}

<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\ChatMessage;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ChatController extends BaseController {

	/**
	 * @Template()
	 */
	public function showAction(Request $request) {
		$user = $this->getUser();
		
		if ($request->getMethod() == 'POST') {
			$content = $this->cleanText($request->get('message'));
			if ($content) {
				$message = new ChatMessage;
				$message->setAuthor($user);
				$message->setContent($content);
				$this->getManager()->persist($message);
				$this->getManager()->flush();
			}
		}
		$messages = [];
		$users = [
			$user->getId() => $user,
		];
		foreach ($this->getRepository('ChatMessage')->findLasts(20) as $message) {
			$messages[] = $message;
			$users[$message->getAuthor()->getId()] = $message->getAuthor();
		}
		
		$connectedUsersId = [];
		foreach ($this->getRepository('ComTSoUserBundle:User')->findConnected() as $connectedUser) {
			$connectedUsersId[] = $connectedUser->getId();
			$users[$connectedUser->getId()] = $connectedUser;
		}
		$response = new JsonResponse(['messages' => $messages, 'users' => $users, 'current_user_id' => $user->getId(), 'connected_users_id' => $connectedUsersId]);
		return $response;
	}
	
}

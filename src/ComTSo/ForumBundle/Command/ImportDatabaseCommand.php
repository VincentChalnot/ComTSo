<?php

namespace ComTSo\ForumBundle\Command;

use ComTSo\ForumBundle\Decoda\PhpEngine;
use ComTSo\ForumBundle\Entity\ChatMessage;
use ComTSo\ForumBundle\Entity\Comment;
use ComTSo\ForumBundle\Entity\Message;
use ComTSo\ForumBundle\Entity\Photo;
use ComTSo\ForumBundle\Entity\Quote;
use ComTSo\ForumBundle\Entity\Topic;
use ComTSo\ForumBundle\Lib\Utils;
use ComTSo\UserBundle\Entity\User;
use DateTime;
use Decoda\Decoda;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\Query;
use Exception;
use HTMLPurifier;
use JoliTypo\Fixer;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImportDatabaseCommand extends ContainerAwareCommand {

	/**
	 * @var InputInterface
	 */
	protected $input;

	/**
	 * @var OutputInterface 
	 */
	protected $output;

	/**
	 * @var ObjectManager
	 */
	protected $em;

	/**
	 * @var HTMLPurifier
	 */
	protected $htmlPurifier;

	/**
	 * @var Fixer
	 */
	protected $typoFixer;

	/**
	 *
	 * @var Decoda
	 */
	protected $decoda;

	protected function configure() {
		$this->setName('comtso:import')
				->setDescription('Import data from the old model');
	}

	protected function initialize(InputInterface $input, OutputInterface $output) {
		parent::initialize($input, $output);
		$this->input = $input;
		$this->output = $output;
		$this->em = $this->getDoctrine()->getManager();
		$this->htmlPurifier = $this->getContainer()->get('exercise_html_purifier.default');
		$this->typoFixer = $this->getContainer()->get('joli_typo.fixer.fr');
		$this->decoda = new Decoda('', array(
			'strictMode' => false,
			'escapeHtml' => false,
			'locale' => 'fr-fr',
			'removeEmpty' => true,
		));
		$this->decoda->addFilter(new \Decoda\Filter\DefaultFilter);
		$this->decoda->addFilter(new \Decoda\Filter\UrlFilter);
		$this->decoda->addFilter(new \Decoda\Filter\EmailFilter);
		$this->decoda->addFilter(new \Decoda\Filter\TextFilter);
		$this->decoda->addFilter(new \Decoda\Filter\BlockFilter);
		$this->decoda->addFilter(new \Decoda\Filter\ImageFilter);
		$this->decoda->addFilter(new \Decoda\Filter\ListFilter);
		$this->decoda->addFilter(new \Decoda\Filter\QuoteFilter);
		$engine = new PhpEngine;
		$engine->addPath(__DIR__."/../Decoda/templates/");
		$this->decoda->setEngine($engine);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$forumRepo = $this->getDoctrine()->getRepository('ComTSoForumBundle:Forum');
		$forumsByOldIds = [
			0 => $forumRepo->find("le-bac-a-sable"),
			1 => $forumRepo->find("organisation"),
			2 => $forumRepo->find("cabinet-du-dr-schtroumph"),
			3 => $forumRepo->find("archives"),
		];
		$users = $this->importUsers();
//		$topics = $this->importTopics($forumsByOldIds, $users);
//		$this->importPosts($topics, $users);
//		$this->importQuotes($users);
//		$this->importMessages($users);
//		$this->importChatMessages();
		$this->importPhotos($users);
	}

	protected function importUsers() {
		$this->truncateTable(get_class(new User));
		$stmt = $this->em->getConnection()->executeQuery('SELECT * FROM users');
		$users = [];
		$this->output->writeln("<info>Importing Users</info>");
		while ($rs = $stmt->fetch(Query::HYDRATE_ARRAY)) {
			$user = new User;
			$user->setName($this->cleanText($rs['prenom']));
			$user->setSurname($this->cleanText($rs['nom']));
			$birthday = new DateTime;
			$birthday->setTimestamp($rs['naissance']);
			$user->setBirthday($birthday);
			$user->setAddress($rs['adresse']);
			$user->setPhone($rs['telephone']);
			$user->setActivities($rs['activites']);
			$user->setSignature($rs['signature']);
			$user->setWebsite($rs['siteweb']);
			$registeredAt = new DateTime;
			$registeredAt->setTimestamp($rs['registered']);
			$user->setRegisteredAt($registeredAt);
			$user->setUsername($rs['user']);
			$user->setEmail($rs['email']);
			$user->setEnabled(true);
			$lastLogin = new DateTime;
			$lastLogin->setTimestamp($rs['last_activity']);
			$user->setLastLogin($lastLogin);
			$user->setLastActivity($lastLogin);
			$user->setRoles(['ROLE_USER']);

			$user->setPlainPassword($rs['pass']); // Will work with custom authenticationprovider
			$this->em->persist($user);
			$this->em->flush();
			$users[$rs['user_id']] = $user;
		}
		return $users;
	}

	protected function importTopics($forums, $users) {
		$this->truncateTable(get_class(new Topic));
		$stmt = $this->em->getConnection()->executeQuery('SELECT COUNT(*) FROM topics WHERE flag = 0');
		$topicCount = $stmt->fetch(Query::HYDRATE_SINGLE_SCALAR)[0];
		$progress = $this->getHelperSet()->get('progress');
		$progress->start($this->output, $topicCount);

		$stmt = $this->em->getConnection()->executeQuery('SELECT * FROM topics WHERE flag = 0');
		$topics = [];
		$this->output->writeln("<info>Importing Topics</info>");
		while ($rs = $stmt->fetch(Query::HYDRATE_ARRAY)) {
			$topic = new Topic;
			$topic->setId($rs['topic_id']);
			$topic->setAuthor($users[$rs['user_id']]);
			$topic->setTitle(Utils::upperCaseFirst($this->cleanText($rs['title'])));
			$topic->setContent($this->cleanHtml($rs['summary']));
			$topic->setForum($forums[$rs['forum_id']]);
			$topic->setViews($rs['views']);
			$topic->setCommentCount($rs['nbr_posts']);
			$createdAt = new DateTime;
			$createdAt->setTimestamp($rs['creation']);
			$topic->setCreatedAt($createdAt);
			$updatedAt = new DateTime;
			$updatedAt->setTimestamp($rs['modification']);
			$topic->setUpdatedAt($updatedAt);
			$this->em->persist($topic);
			$this->em->flush();
			$progress->advance();
			$topics[$rs['topic_id']] = $topic;
		}
		$progress->finish();
		return $topics;
	}

	protected function importPosts($topics, $users) {
		$this->truncateTable(get_class(new Comment));
		$stmt = $this->em->getConnection()->executeQuery('SELECT COUNT(*) FROM posts');
		$postCount = $stmt->fetch(Query::HYDRATE_SINGLE_SCALAR)[0];
		$progress = $this->getHelperSet()->get('progress');
		$progress->setBarWidth(100);
		$progress->start($this->output, $postCount);

		$stmt = $this->em->getConnection()->executeQuery('SELECT * FROM posts');
		$this->output->writeln("<info>Importing Comments</info>");
		while ($rs = $stmt->fetch(Query::HYDRATE_ARRAY)) {
			if (!isset($topics[$rs['topic_id']])) {
				$this->output->writeln("<error>Unknown Topic: #{$rs['post_id']}</error>\n<info>Post #{$rs['post_id']} ({$users[$rs['user_id']]})</info>\n<comment>{$rs['text']}</comment>\n\n");
				$progress->advance();
				continue;
			}
			if (!isset($users[$rs['user_id']])) {
				$this->output->writeln("<error>Unknown User: #{$rs['user_id']}</error>\n<info>Post #{$rs['post_id']}</info>\n<comment>{$rs['text']}</comment>\n\n");
				$progress->advance();
				continue;
			}
			$comment = new Comment;
			$comment->setId($rs['post_id']);
			$comment->setAuthor($users[$rs['user_id']]);
			$comment->setTopic($topics[$rs['topic_id']]);
			$content = $rs['text'];
			if ($rs['creation'] <= 1245115717) { // Migration date to new forum
				$content = $this->parseBBCode($content);
			}
			$comment->setContent($this->cleanHtml($content));
			$createdAt = new DateTime;
			$createdAt->setTimestamp($rs['creation']);
			$comment->setCreatedAt($createdAt);
			if ($rs['modification']) {
				$updatedAt = new DateTime;
				$updatedAt->setTimestamp($rs['modification']);
			} else {
				$updatedAt = $createdAt;
			}
			$comment->setUpdatedAt($updatedAt);
			$this->em->persist($comment);
			$this->em->flush();
			$this->em->detach($comment);
			$progress->advance();
		}
		$progress->finish();
	}

	protected function importQuotes($users) {
		$this->truncateTable(get_class(new Quote));
		$stmt = $this->em->getConnection()->executeQuery('SELECT COUNT(*) FROM quotes');
		$quoteCount = $stmt->fetch(Query::HYDRATE_SINGLE_SCALAR)[0];
		$progress = $this->getHelperSet()->get('progress');
		$progress->start($this->output, $quoteCount);

		$stmt = $this->em->getConnection()->executeQuery('SELECT * FROM quotes');
		$this->output->writeln("<info>Importing Quotes</info>");
		while ($rs = $stmt->fetch(Query::HYDRATE_ARRAY)) {
			$quote = new Quote;
			$userId = $rs['user_id'];
			if (!isset($users[$userId])) {
				$userId = 2;
			}
			$quote->setAuthor($users[$userId]);
			$quote->setContent($this->cleanText($rs['text']));
			$quote->setOriginalAuthor($rs['author']);
			$quote->setCreatedAt(new DateTime);
			$quote->setUpdatedAt(new DateTime);
			$this->em->persist($quote);
			$this->em->flush();
			$progress->advance();
		}
		$progress->finish();
	}
	
	protected function importMessages($users) {
		$this->truncateTable(get_class(new Message));
		$stmt = $this->em->getConnection()->executeQuery('SELECT COUNT(*) FROM messages');
		$messageCount = $stmt->fetch(Query::HYDRATE_SINGLE_SCALAR)[0];
		$progress = $this->getHelperSet()->get('progress');
		$progress->start($this->output, $messageCount);

		$stmt = $this->em->getConnection()->executeQuery('SELECT * FROM messages');
		$this->output->writeln("<info>Importing Messages</info>");
		while ($rs = $stmt->fetch(Query::HYDRATE_ARRAY)) {
			$message = new Message;
			$authorId = $rs['from'];
			$recipiendId = $rs['to'];
			if (!isset($users[$authorId]) || !isset($users[$recipiendId])) {
				$progress->advance();
				continue;
			}
			$message->setAuthor($users[$authorId]);
			$message->setRecipient($users[$recipiendId]);
			$message->setContent($this->cleanHtml($rs['body']));
			$message->setState($rs['state']);
            $createdAt = new DateTime;
			$createdAt->setTimestamp($rs['date']);
			$message->setCreatedAt($createdAt);
			$message->setUpdatedAt($createdAt);
			$this->em->persist($message);
			$this->em->flush();
			$progress->advance();
		}
		$progress->finish();
	}
	
	protected function importChatMessages() {
		$this->truncateTable(get_class(new ChatMessage));
		$filePath = "{$this->getContainer()->get('kernel')->getRootDir()}/data/chat.html";
		$linecount = 0;
		$handle = fopen($filePath, "r");
		while (!feof($handle)) {
			fgets($handle);
			$linecount++;
		}
		$progress = $this->getHelperSet()->get('progress');
		$progress->start($this->output, $linecount);

		rewind($handle);
		$this->output->writeln("<info>Importing Chat Messages</info>");
		$year = 2009;
		$previousMonth = null;
		while (($line = fgets($handle)) !== false) {
			if (!preg_match("/<strong>([^,]+),<\/strong>\s*<em>le\s*(\d+)\/(\d+)\s+(\d+):(\d+)<\/em>:\s*(.*)<br\s*\/?>/", $line, $infos)) {
				$progress->advance();
				continue;
			}
			list($line, $username, $day, $month, $hour, $minute, $content) = $infos;
			$message = new ChatMessage;
			$author = $this->getDoctrine()->getRepository('ComTSoUserBundle:User')->findOneByUsername($username);
			if (!$author) {
				$progress->advance();
				continue;
			}
			if($previousMonth && $previousMonth > $month) {
				$year++;
			}
			$message->setAuthor($author);
			$message->setContent($this->cleanText($content));
			$createdAt = new DateTime;
			$createdAt->setDate($year, $month, $day);
			$createdAt->setTime($hour, $minute);
			$message->setCreatedAt($createdAt);
			$message->setUpdatedAt($createdAt);
			$this->em->persist($message);
			$this->em->flush();
			$progress->advance();
			$previousMonth = $month;
		}
		$progress->finish();
		fclose($handle);
	}
	
	protected function importPhotos($users) {
		$this->truncateTable(get_class(new Photo));
		$stmt = $this->em->getConnection()->executeQuery('SELECT COUNT(*) FROM photos');
		$photoCount = $stmt->fetch(Query::HYDRATE_SINGLE_SCALAR)[0];
		$progress = $this->getHelperSet()->get('progress');
		$progress->start($this->output, $photoCount);

		$stmt = $this->em->getConnection()->executeQuery('SELECT * FROM photos');
		$this->output->writeln("<info>Importing Photos</info>");
		while ($rs = $stmt->fetch(Query::HYDRATE_ARRAY)) {
			$authorId = $rs['user_id'];
			if (!isset($users[$authorId])) {
				$progress->advance();
				continue;
			}
			$id = $rs['photo_id'];
			$filename = "{$this->getContainer()->getParameter('comtso.photo_dir')}/{$id}.jpg";
			if (!file_exists($filename)) {
				$progress->advance();
				continue;
			}
			$photo = $this->getContainer()->get('comtso.image.uploader')->handleFile($filename);
			
			$photo->setAuthor($users[$authorId]);
			$photo->setTitle($this->cleanText($rs['title']));
            $createdAt = new DateTime;
			$createdAt->setTimestamp($rs['date']);
			$photo->setCreatedAt($createdAt);
			$photo->setUpdatedAt($createdAt);
			$this->em->persist($photo);
			$this->em->flush();
			$progress->advance();
		}
		$progress->finish();
	}

	/**
	 * 
	 * @return Registry
	 */
	protected function getDoctrine() {
		return $this->getContainer()->get('doctrine');
	}

	protected function cleanHtml($html) {
		$html = str_replace('images/smilies/', '/images/smilies/', $html);
		$html = $this->typoFixer->fix($html);
		$html = $this->htmlPurifier->purify($html);
		return $html;
	}

	protected function cleanText($html, $cut = null) {
		$html = $this->cleanHtml($html);
		$txt = Utils::convertToText($html);
		if ($cut) {
			$txt = Utils::shorten($txt, $cut);
		}
		return str_replace("\n", ' ', $txt);
	}

	protected function generateStub($object) {
		foreach (get_class_methods($object) as $method) {
			if (substr($method, 0, 3) === 'set') {
				$field = strtolower(substr($method, 3));
				echo "\$user->{$method}(\$rs['{$field}']);\n";
			}
		}
	}

	protected function truncateTable($className) {
		$em = $this->getDoctrine()->getManager();
		$cmd = $em->getClassMetadata($className);
		$connection = $em->getConnection();
		$dbPlatform = $connection->getDatabasePlatform();
		$connection->beginTransaction();
		try {
			$connection->query('SET FOREIGN_KEY_CHECKS=0');
			$q = $dbPlatform->getTruncateTableSql($cmd->getTableName());
			$connection->executeUpdate($q);
			$connection->query('SET FOREIGN_KEY_CHECKS=1');
			$connection->commit();
		} catch (Exception $e) {
			$connection->rollback();
			throw $e;
		}
	}

	protected function parseBBCode($string) {
		$string = strtr($string, [
			'â‚¬' => '€',
			'â€š' => '‚',
			'Æ\'' => 'ƒ',
			'â€ž' => '"',
			'â€¦' => '…',
			'â€¡' => '‡',
			'Ë†' => 'ˆ',
			'â€°' => '‰',
			'Å' => 'Š',
			'â€¹' => '‹',
			'Å\'' => 'Œ',
			'Å½' => 'Ž',
			'â€˜' => '\'',
			'â€™' => '’',
			'â€œ' => '“',
			'â€' => '"',
			'â€¢' => '•',
			'â€"' => '–',
			'Ëœ' => '˜',
			'â"¢' => '™',
			'Å¡' => 'š',
			'â€º' => '›',
			'Å"' => 'œ',
			'Å¾' => 'ž',
			'Å¸' => 'Ÿ',
			'Â¡' => '¡',
			'Â¢' => '¢',
			'Â£' => '£',
			'Â¤' => '¤',
			'Â¥' => '¥',
			'Â¦' => '¦',
			'Â§' => '§',
			'Â¨' => '¨',
			'Â©' => '©',
			'Âª' => 'ª',
			'Â«' => '«',
			'Â¬' => '¬',
			'Â®' => '®',
			'Â¯' => '¯',
			'Â°' => '°',
			'Â±' => '±',
			'Â²' => '²',
			'Â³' => '³',
			'Â´' => '´',
			'Âµ' => 'µ',
			'Â¶' => '¶',
			'Â·' => '·',
			'Â¸' => '¸',
			'Â¹' => '¹',
			'Âº' => 'º',
			'Â»' => '»',
			'Â¼' => '¼',
			'Â½' => '½',
			'Â¾' => '¾',
			'Â¿' => '¿',
			'Ã€' => 'À',
			'Ã' => 'í',
			'Ã‚' => 'Â',
			'Ãƒ' => 'Ã',
			'Ã"' => 'Ô',
			'Ã…' => 'Å',
			'Ã†' => 'Æ',
			'Ã‡' => 'Ç',
			'Ãˆ' => 'È',
			'Ã‰' => 'É',
			'ÃŠ' => 'Ê',
			'Ã‹' => 'Ë',
			'ÃŒ    ' => 'Ì',
			'ÃŽ' => 'Î',
			'ÃŸ' => 'ß',
			'Ã\'' => 'Ò',
			'Ã•' => 'Õ',
			'Ã–' => 'Ö',
			'Ã—' => '×',
			'Ã˜' => 'Ø',
			'Ã™' => 'Ù',
			'Ãš' => 'Ú',
			'Ã›' => 'Û',
			'Ãœ' => 'Ü',
			'Ãž' => 'Þ',
			'Ã¡' => 'á',
			'Ã¢' => 'â',
			'Ã£' => 'ã',
			'Ã¤' => 'ä',
			'Ã¥' => 'å',
			'Ã¦' => 'æ',
			'Ã§' => 'ç',
			'Ã¨' => 'è',
			'Ã©' => 'é',
			'Ãª' => 'ê',
			'Ã«' => 'ë',
			'Ã¬' => 'ì',
			'Ã®' => 'î',
			'Ã¯' => 'ï',
			'Ã°' => 'ð',
			'Ã±' => 'ñ',
			'Ã²' => 'ò',
			'Ã³' => 'ó',
			'Ã´' => 'ô',
			'Ãµ' => 'õ',
			'Ã¶' => 'ö',
			'Ã·' => '÷',
			'Ã¸' => 'ø',
			'Ã¹' => 'ù',
			'Ãº' => 'ú',
			'Ã»' => 'û',
			'Ã¼' => 'ü',
			'Ã½' => 'ý',
			'Ã¾' => 'þ',
			'Ã¿' => 'ÿ',
		]);
		$string = preg_replace("%\[(\/?)([^:]+):[a-z0-9]+\]%", "[$1$2]", $string);
		$this->decoda->reset($string);
		$value = $this->decoda->parse();
		return $value;
	}

	protected function parseDecodaErrors() {
		$nesting = array();
		$closing = array();
		$scope = array();

		foreach ($this->decoda->getErrors() as $error) {
			switch ($error['type']) {
				case Decoda::ERROR_NESTING: $nesting[] = $error['tag'];
					break;
				case Decoda::ERROR_CLOSING: $closing[] = $error['tag'];
					break;
				case Decoda::ERROR_SCOPE: $scope[] = $error['child'].' in '.$error['parent'];
					break;
}
		}
		echo "\n";
		if (!empty($nesting)) {
			$this->output->writeln(sprintf('<error>The following tags have been nested in the wrong order: %s</error>', implode(', ', $nesting)));
		}

		if (!empty($closing)) {
			$this->output->writeln(sprintf('<error>The following tags have no closing tag: %s</error>', implode(', ', $closing)));
		}

		if (!empty($scope)) {
			$this->output->writeln(sprintf('<error>The following tags can not be placed within a specific tag: %s</error>', implode(', ', $scope)));
		}
	}

}

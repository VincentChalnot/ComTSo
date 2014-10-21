<?php

namespace ComTSo\ForumBundle\Entity\Behavior;

use ComTSo\UserBundle\Entity\User;
use Symfony\Component\Security\Core\User\UserInterface;

trait Authorable
{
    /**
     * Author of the entity
     *
     * @var User
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="ComTSo\UserBundle\Entity\User")
     */
    protected $author;

    public function setAuthor(UserInterface $author)
    {
        $this->author = $author;

        return $this;
    }

    public function getAuthor()
    {
        return $this->author;
    }

    public function getAuthorName()
    {
        if (!$this->getAuthor()) {
            return 'Anonymous';
        }

        return $this->getAuthor()->getUsername();
    }

}

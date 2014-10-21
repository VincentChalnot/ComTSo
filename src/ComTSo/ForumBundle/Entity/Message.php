<?php

namespace ComTSo\ForumBundle\Entity;

use ComTSo\UserBundle\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @ORM\Table(name="ctso_message")
 * @ORM\Entity(repositoryClass="ComTSo\ForumBundle\Entity\MessageRepository")
 */
class Message implements Routable
{
    use Behavior\Authorable,
     Behavior\Timestampable,
     Behavior\ContentEditable;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * Recipient of the entity
     *
     * @var User
     * @ORM\ManyToOne(targetEntity="ComTSo\UserBundle\Entity\User")
     */
    protected $recipient;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    protected $state;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    public function setRecipient(UserInterface $recipient)
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function getRecipient()
    {
        return $this->recipient;
    }

    public function getState()
    {
        return $this->state;
    }

    public function setState($state)
    {
        $this->state = $state;

        return $this;
    }

    public function getRoutingParameters()
    {
        return ['id' => $this->getId()];
    }

}

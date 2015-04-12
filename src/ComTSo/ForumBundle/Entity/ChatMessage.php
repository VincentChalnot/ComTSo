<?php

namespace ComTSo\ForumBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="ctso_chat_message")
 * @ORM\Entity(repositoryClass="ComTSo\ForumBundle\Entity\ChatMessageRepository")
 */
class ChatMessage implements \JsonSerializable, Routable
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
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param int $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getAuthorId()
    {
        if ($this->getAuthor()) {
            return $this->getAuthor()->getId();
        }
    }

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return [
            'id' => $this->getId(),
            'author_id' => $this->getAuthorId(),
            'content' => $this->getContent(),
            'created_at' => $this->getCreatedAt(\DateTime::W3C),
            'updated_at' => $this->getUpdatedAt(\DateTime::W3C),
        ];
    }

    /**
     * @return array
     */
    public function getRoutingParameters()
    {
        return ['id' => $this->getId()];
    }

}

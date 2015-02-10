<?php

namespace ComTSo\ForumBundle\Entity;

use ComTSo\ForumBundle\Lib\Utils;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="ctso_forum")
 * @ORM\Entity(repositoryClass="ComTSo\ForumBundle\Entity\ForumRepository")
 */
class Forum implements Routable
{
    use Behavior\Authorable,
     Behavior\Timestampable,
     Behavior\Titleable,
     Behavior\ContentEditable;

    /**
     * @ORM\Id
     * @ORM\Column(type="string", length=32)
     */
    protected $id;

    /**
     * @ORM\Column(name="ord", type="integer", nullable=true)
     */
    protected $order;

    /**
     * Topics associated to this forum
     * @var Topic[]
     * @ORM\OneToMany(targetEntity="ComTSo\ForumBundle\Entity\Topic", mappedBy="forum")
     * @ORM\OrderBy({"createdAt" = "DESC"})
     */
    protected $topics;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->topics = new ArrayCollection();
    }

    /**
     * @param  string $id
     * @return Forum
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    public function getOrder()
    {
        return $this->order;
    }

    public function setOrder($order)
    {
        $this->order = $order;

        return $this;
    }


    /**
     * Add topics
     *
     * @param  Topic $topic
     * @return Forum
     */
    public function addTopic(Topic $topic)
    {
        $this->topics[] = $topic;

        return $this;
    }

    /**
     * Remove topics
     *
     * @param Topic $topic
     */
    public function removeTopic(Topic $topic)
    {
        $this->topics->removeElement($topic);
    }

    /**
     * Get topics
     *
     * @return ArrayCollection
     */
    public function getTopics()
    {
        return $this->topics;
    }

    public function setTitle($text)
    {
        if (!$this->id) {
            $this->id = Utils::slugify($text);
        }
        $this->title = $text;
        return $this;
    }

    public function getRoutingParameters()
    {
        return ['id' => $this->getId()];
    }

}

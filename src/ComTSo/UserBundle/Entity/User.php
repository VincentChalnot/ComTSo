<?php

namespace ComTSo\UserBundle\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use FOS\UserBundle\Model\User as BaseUser;
use ComTSo\ForumBundle\Entity\Photo;
use ComTSo\ForumBundle\Entity\Topic;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * Object
 *
 * @ORM\Table(name="fos_user")
 * @ORM\Entity(repositoryClass="ComTSo\UserBundle\Entity\UserRepository")
 */
class User extends BaseUser implements \JsonSerializable, \ComTSo\ForumBundle\Entity\Routable
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=32)
     */
    protected $name;

    /**
     * @ORM\Column(type="string", length=32)
     */
    protected $surname;

    /**
     * @var \DateTime
     * @ORM\Column(type="date")
     */
    protected $birthday;

    /**
     * @ORM\Column(type="text")
     */
    protected $address;

    /**
     * @ORM\Column(type="string", length=20)
     */
    protected $phone;

    /**
     * @ORM\Column(type="text")
     */
    protected $activities;

    /**
     * @ORM\Column(type="text")
     */
    protected $signature;

    /**
     * @ORM\Column(type="string", length=128)
     */
    protected $website;

    /**
     * @var \DateTime
     * @ORM\Column(name="registered_at", type="datetime")
     */
    protected $registeredAt;

    /**
     * @var \DateTime
     * @ORM\Column(name="previous_login", type="datetime", nullable=true)
     */
    protected $previousLogin;

    /**
     * @var \DateTime
     * @ORM\Column(name="last_activity", type="datetime", nullable=true)
     */
    protected $lastActivity;

    /**
     * @var Photo
     * @ORM\ManyToOne(targetEntity="ComTSo\ForumBundle\Entity\Photo", fetch="EAGER")
     */
    protected $avatar;
    
    /**
     *
     * @var Topic[]
     * @ORM\ManyToMany(targetEntity="ComTSo\ForumBundle\Entity\Topic")
     * @ORM\JoinTable(name="ctso_starred_topic")
     */
    protected $starredTopics;

    public function __construct()
    {
        parent::__construct();
        $this->starredTopics = new ArrayCollection;
    }
    
    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
     *
     * @param  string $name
     * @return User
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set surname
     *
     * @param  string $surname
     * @return User
     */
    public function setSurname($surname)
    {
        $this->surname = $surname;

        return $this;
    }

    /**
     * Get surname
     *
     * @return string
     */
    public function getSurname()
    {
        return $this->surname;
    }

    /**
     * Set birthday
     *
     * @param  DateTime $birthday
     * @return User
     */
    public function setBirthday(DateTime $birthday)
    {
        $this->birthday = $birthday;

        return $this;
    }

    /**
     * Get birthday
     *
     * @return DateTime
     */
    public function getBirthday()
    {
        return $this->birthday;
    }

    /**
     * Get age
     *
     * @return DateTime
     */
    public function getAge()
    {
        $diff = $this->birthday->diff(new DateTime());

        return $diff->y;
    }

    /**
     * Set address
     *
     * @param  string $address
     * @return User
     */
    public function setAddress($address)
    {
        $this->address = $address;

        return $this;
    }

    /**
     * Get address
     *
     * @return string
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * Set phone
     *
     * @param  string $phone
     * @return User
     */
    public function setPhone($phone)
    {
        $this->phone = $phone;

        return $this;
    }

    /**
     * Get phone
     *
     * @return string
     */
    public function getPhone()
    {
        return $this->phone;
    }

    /**
     * Set activities
     *
     * @param  string $activities
     * @return User
     */
    public function setActivities($activities)
    {
        $this->activities = $activities;

        return $this;
    }

    /**
     * Get activities
     *
     * @return string
     */
    public function getActivities()
    {
        return $this->activities;
    }

    /**
     * Set signature
     *
     * @param  string $signature
     * @return User
     */
    public function setSignature($signature)
    {
        $this->signature = $signature;

        return $this;
    }

    /**
     * Get signature
     *
     * @return string
     */
    public function getSignature()
    {
        return $this->signature;
    }

    /**
     * Set website
     *
     * @param  string $website
     * @return User
     */
    public function setWebsite($website)
    {
        $this->website = $website;

        return $this;
    }

    /**
     * Get website
     *
     * @return string
     */
    public function getWebsite()
    {
        return $this->website;
    }

    /**
     * Set registered
     *
     * @param  DateTime $registered
     * @return User
     */
    public function setRegisteredAt(DateTime $registeredAt)
    {
        $this->registeredAt = $registeredAt;

        return $this;
    }

    /**
     * Get registered
     *
     * @return DateTime
     */
    public function getRegisteredAt()
    {
        return $this->registeredAt;
    }

    public function setLastLogin(DateTime $time = null)
    {
        $this->previousLogin = $this->lastLogin;

        return parent::setLastLogin($time);
    }

    public function getPreviousLogin()
    {
        return $this->previousLogin;
    }

    public function getLastActivity()
    {
        return $this->lastActivity;
    }

    public function setLastActivity(\DateTime $lastActivity)
    {
        $this->lastActivity = $lastActivity;

        return $this;
    }

    /**
     *
     * @return Photo
     */
    public function getAvatar()
    {
        return $this->avatar;
    }

    /**
     *
     * @return Photo
     */
    public function setAvatar(Photo $photo = null)
    {
        return $this->avatar = $photo;
    }
    
    /**
     * Add StarredTopic
     *
     * @param  Topic $topic
     * @return User
     */
    public function addStarredTopic(Topic $topic)
    {
        $this->starredTopics[] = $topic;
        return $this;
    }

    /**
     * Remove Starredtopic
     *
     * @param Topic $topic
     * @return User
     */
    public function removeStarredTopic(Topic $topic)
    {
        $this->starredTopics->removeElement($topic);
        return $this;
    }

    /**
     * Get topics
     *
     * @return ArrayCollection
     */
    public function getStarredTopics()
    {
        return $this->starredTopics;
    }
    
    /**
     * 
     * @param Topic $topic
     * @return bool
     */
    public function isStarred(Topic $topic)
    {
        return $this->starredTopics->contains($topic);
    }

    
    public function jsonSerialize()
    {
        return [
            'id' => $this->getId(),
            'username' => $this->getUsername(),
            'usernameCanonical' => $this->getUsernameCanonical(),
            'email' => $this->getEmail(),
            'name' => $this->getName(),
            'surname' => $this->getSurname(),
            'signature' => $this->getSignature(),
            'avatar' => $this->getAvatar(),
        ];
    }

    public function getRoutingParameters()
    {
        return ['usernameCanonical' => $this->getUsernameCanonical()];
    }

}

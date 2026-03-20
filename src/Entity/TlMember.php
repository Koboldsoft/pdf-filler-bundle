<?php

namespace Koboldsoft\PdfFillerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="tl_member")
 */
class TlMember
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(name="firstname", type="string", length=255, nullable=true)
     */
    private $firstname;

    /**
     * @ORM\Column(name="lastname", type="string", length=255, nullable=true)
     */
    private $lastname;
    
    /**
     * @ORM\Column(name="phone", type="string", length=255, nullable=true)
     */
    private $phone;
    
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
            
     }
    
    public function getLastname(): ?string
    {
        return $this->lastname;
            
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }
    
}
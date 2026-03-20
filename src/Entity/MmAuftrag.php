<?php

namespace Koboldsoft\PdfFillerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="mm_auftrag")
 */
class MmAuftrag
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $datum_eintritt;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $datum_austritt;

    /**
     * @ORM\Column(name="f_massnahme", type="string", length=255, nullable=true)
     */
    private $f_massnahme;
    
    /**
     * @ORM\Column(name="f_massnahmenr", type="string", length=255, nullable=true)
     */
    private $f_massnahmenr;
    
    /**
     * @ORM\Column(name="id_coach", type="integer", nullable=true)
     */
    private $id_coach;
    
    /**
     * @ORM\Column(name="id_massnahme", type="integer", nullable=true)
     */
    private $id_massnahme;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDatumEintritt(): ?string
    {
        if (!$this->datum_eintritt) {
            return null;
        }
        
        return date('d.m.Y', $this->datum_eintritt);
    }
    
    public function getDatumAustritt(): ?string
    {
        if (!$this->datum_austritt) {
            return null;
        }
        
        return date('d.m.Y', $this->datum_austritt);
    }

    public function getFMassnahme(): ?string
    {
        return $this->f_massnahme;
    }
    
    public function getFMassnahmenr(): ?string
    {
        return $this->f_massnahmenr;
    }
    
    public function getIdCoach(): ?string
    {
        return $this->id_coach;
    }
    
    public function getIdMassnahme(): ?string
    {
        return $this->id_massnahme;
    }
    

}
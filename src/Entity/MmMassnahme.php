<?php
namespace Koboldsoft\PdfFillerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 *
 * @ORM\Entity
 * @ORM\Table(name="mm_massnahme")
 */
class MmMassnahme
{

    /**
     *
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     *
     * @ORM\Column(name="beginn", type="integer")
     */
    private $beginn;

    /**
     *
     * @ORM\Column(name="ende", type="integer")
     */
    private $ende;

    public function getId(): ?int
    {
        // return $this->id;
    }

    public function getBeginn(): ?string
    {
        if (! $this->beginn) {
            return null;
        }
        
        return date('d.m.Y', $this->beginn);
    }
    
    public function getEnde(): ?string
    {
        if (! $this->ende) {
            return null;
        }
        
        return date('d.m.Y', $this->ende);
    }
}
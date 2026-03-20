<?php
// neu
namespace Koboldsoft\PdfFillerBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Koboldsoft\PdfFillerBundle\Entity\MmMassnahme;

class MmMassnahmeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MmMassnahme::class);
    }

    /**
     * Find a massnahme by its ID (returns one entity or null)
     */
    public function findMassnahmeById(int $id): ?MmMassnahme
    {
        return $this->find($id);
    }
}

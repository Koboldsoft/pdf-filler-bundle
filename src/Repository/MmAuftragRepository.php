<?php
// neu
namespace Koboldsoft\PdfFillerBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Koboldsoft\PdfFillerBundle\Entity\MmAuftrag;

class MmAuftragRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MmAuftrag::class);
    }

    /**
     * Find an Auftrag by its ID (returns one entity or null)
     */
    public function findAuftragById(int $id): ?MmAuftrag
    {
        return $this->find($id);
    }
}

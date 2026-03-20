<?php
// neu
namespace Koboldsoft\PdfFillerBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Koboldsoft\PdfFillerBundle\Entity\TlMember;

class TlMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TlMember::class);
    }

    /**
     * Find an member by its ID (returns one entity or null)
     */
    public function findMemberById(int $id): ?TlMember
    {
        return $this->find($id);
    }
}

<?php

namespace App\Repository;

use App\Entity\Link;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for the Link entity.
 *
 * Inherited methods from ServiceEntityRepository cover the common cases
 * (find, findOneBy, findBy, findAll). Custom query methods are added below
 * for operations that need JOINs or non-trivial DQL.
 *
 * @extends ServiceEntityRepository<Link>
 */
class LinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Link::class);
    }

    /**
     * Finds a link by its short code and eagerly loads all associated clicks
     * in a single LEFT JOIN query.
     *
     * Using addSelect('c') instead of a lazy load avoids the N+1 problem on
     * the stats endpoint, where every click would otherwise trigger a separate
     * SELECT.
     *
     * @param string $code The short code to look up
     *
     * @return Link|null The link with its clicks collection fully hydrated,
     *                   or null if no link with that code exists
     */
    public function findByCodeWithClicks(string $code): ?Link
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.clicks', 'c')
            ->addSelect('c')
            ->where('l.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

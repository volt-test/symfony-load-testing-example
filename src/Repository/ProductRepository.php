<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @return array{items: list<Product>, total: int}
     */
    public function findPage(int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $total = (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return ['items' => $qb->getQuery()->getResult(), 'total' => $total];
    }

    /** @return list<Product> */
    public function findFeatured(int $limit = 12): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Atomically reserve stock. Returns false when there is not enough left.
     */
    public function reserveStock(int $productId, int $quantity): bool
    {
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE product SET stock = stock - :qty WHERE id = :id AND stock >= :qty',
            ['qty' => $quantity, 'id' => $productId]
        );

        return $affected === 1;
    }
}

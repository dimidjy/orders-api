<?php

namespace App\Repository;

use App\Entity\OrderItem;
use App\Entity\Product;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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
     * A simple QueryBuilder condition that findBy() cannot express (">" instead of "=").
     *
     * @return Product[]
     */
    public function findInStock(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.stock > 0')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A query parameter: products that are running out, including those already sold out.
     *
     * @return Product[]
     */
    public function findLowStock(int $threshold = 5): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.stock <= :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('p.stock', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Joining an entity without an association: Product has no $items collection,
     * so the join names OrderItem explicitly and gives the condition with WITH.
     *
     * @return list<array{product: Product, sold: int}>
     */
    public function findBestSellers(int $limit = 3): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p', 'SUM(i.quantity) AS sold')
            ->join(OrderItem::class, 'i', 'WITH', 'i.product = p')
            ->join('i.parentOrder', 'o')
            ->andWhere('o.status != :cancelled')
            ->setParameter('cancelled', OrderStatus::Cancelled)
            ->groupBy('p.id')
            ->orderBy('sold', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => [
            'product' => $row[0],
            'sold' => (int) $row['sold'],
        ], $rows);
    }

    /**
     * Plain SQL through the DBAL connection, for features DQL does not have (here the RANK() window function).
     * Uses table and column names, and returns arrays instead of entities.
     *
     * @return list<array{sku: string, name: string, revenue: int, rank: int}>
     */
    public function rankByRevenue(): array
    {
        $sql = <<<'SQL'
            SELECT p.sku, p.name,
                   SUM(i.quantity * i.unit_price) AS revenue,
                   RANK() OVER (ORDER BY SUM(i.quantity * i.unit_price) DESC) AS rank
            FROM product p
            JOIN order_item i ON i.product_id = p.id
            JOIN orders o ON o.id = i.order_id
            WHERE o.status IN (:statuses)
            GROUP BY p.id
            ORDER BY rank, p.name
            SQL;

        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            ['statuses' => [OrderStatus::Paid->value, OrderStatus::Shipped->value]],
            ['statuses' => ArrayParameterType::STRING],
        );
    }
}

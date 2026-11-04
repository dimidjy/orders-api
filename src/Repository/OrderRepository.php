<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * Built-in findBy(): an enum value can be used directly as a criterion.
     *
     * @return Order[]
     */
    public function findByStatus(OrderStatus $status): array
    {
        return $this->findBy(['status' => $status], ['id' => 'ASC']);
    }

    /**
     * Fetch join: loads orders, their items and products in ONE query.
     * Without addSelect(), every $order->getItems() would run another query (the "N+1" problem).
     *
     * @return Order[]
     */
    public function findForCustomerWithItems(Customer $customer): array
    {
        return $this->createQueryBuilder('o')
            ->addSelect('i', 'p')
            ->leftJoin('o.items', 'i')
            ->leftJoin('i.product', 'p')
            ->andWhere('o.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Latest orders with their customer, items and products, loaded together.
     * The Paginator keeps the LIMIT correct when a collection (items) is fetch-joined.
     *
     * @return Order[]
     */
    public function findLatestWithDetails(int $limit = 20): array
    {
        $query = $this->createQueryBuilder('o')
            ->addSelect('c', 'i', 'p')
            ->join('o.customer', 'c')
            ->leftJoin('o.items', 'i')
            ->leftJoin('i.product', 'p')
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery();

        return iterator_to_array(new Paginator($query, fetchJoinCollection: true), false);
    }

    /**
     * GROUP BY returning plain values (no entities): number of orders per status.
     *
     * @return array<string, int> status value => count
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.status AS status', 'COUNT(o.id) AS total')
            ->groupBy('o.status')
            ->orderBy('o.status', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof OrderStatus ? $row['status']->value : $row['status'];
            $counts[$status] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * A single number: total revenue in cents from paid and shipped orders.
     */
    public function totalRevenue(): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COALESCE(SUM(i.quantity * i.unitPrice), 0)')
            ->join('o.items', 'i')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('statuses', [OrderStatus::Paid, OrderStatus::Shipped])
            ->getQuery()
            ->getSingleScalarResult();
    }
}

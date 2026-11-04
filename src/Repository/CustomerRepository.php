<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    /**
     * Built-in findOneBy() wrapped in a named method; normalizes input the same way Customer::setEmail() does.
     */
    public function findOneByEmail(string $email): ?Customer
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /**
     * LEFT JOIN + IS NULL: customers that have never placed an order.
     *
     * @return Customer[]
     */
    public function findWithoutOrders(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.orders', 'o')
            ->andWhere('o.id IS NULL')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Aggregates (SUM, COUNT) with GROUP BY: who spent the most on paid and shipped orders.
     *
     * @return list<array{customer: Customer, spent: int, orderCount: int}>
     */
    public function findTopSpenders(int $limit = 5): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c', 'SUM(i.quantity * i.unitPrice) AS spent', 'COUNT(DISTINCT o.id) AS orderCount')
            ->join('c.orders', 'o')
            ->join('o.items', 'i')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('statuses', [OrderStatus::Paid, OrderStatus::Shipped])
            ->groupBy('c.id')
            ->orderBy('spent', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // Selecting an entity plus extra values gives rows like [0 => Customer, 'spent' => ..., 'orderCount' => ...].
        return array_map(static fn (array $row): array => [
            'customer' => $row[0],
            'spent' => (int) $row['spent'],
            'orderCount' => (int) $row['orderCount'],
        ], $rows);
    }
}

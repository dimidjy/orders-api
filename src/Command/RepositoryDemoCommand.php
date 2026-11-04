<?php

namespace App\Command;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Enum\OrderStatus;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\PersistentCollection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Calls the repository methods in src/Repository and prints what they return.
 * Load test data first: php bin/console doctrine:fixtures:load
 */
#[AsCommand(
    name: 'app:repository-demo',
    description: 'Show examples of reading data through the repositories',
)]
class RepositoryDemoCommand
{
    // Repositories are services, so they are injected like any other dependency.
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly ProductRepository $products,
        private readonly OrderRepository $orders,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->title('Repository examples');

        $io->section('1. Built-in methods (no code in the repository)');
        $keyboard = $this->products->findOneBy(['sku' => 'KB-MECH-01']);
        $io->listing([
            \sprintf('$customers->count([]) → %d customers', $this->customers->count([])),
            \sprintf('$products->count(["stock" => 0]) → %d sold out', $this->products->count(['stock' => 0])),
            \sprintf('$products->findOneBy(["sku" => "KB-MECH-01"]) → %s', $keyboard?->getName() ?? 'null'),
            \sprintf('$products->find(%d) → %s', $keyboard?->getId() ?? 0, $this->products->find($keyboard?->getId() ?? 0)?->getName() ?? 'null'),
            \sprintf('$products->findBy([], ["price" => "DESC"], 1) → %s', $this->products->findBy([], ['price' => 'DESC'], 1)[0]?->getName() ?? '-'),
        ]);

        $io->section('2. CustomerRepository::findOneByEmail("  ANN.LEE@example.com ")');
        $ann = $this->customers->findOneByEmail('  ANN.LEE@example.com ');
        $io->text($ann ? \sprintf('Found #%d %s <%s>', $ann->getId(), $ann->getName(), $ann->getEmail()) : 'Not found');

        $io->section('3. CustomerRepository::findWithoutOrders()');
        $io->listing(array_map(static fn ($c) => $c->getName(), $this->customers->findWithoutOrders()));

        $io->section('4. CustomerRepository::findTopSpenders(3)');
        $io->table(['Customer', 'Orders', 'Spent'], array_map(static fn (array $row) => [
            $row['customer']->getName(),
            $row['orderCount'],
            self::money($row['spent']),
        ], $this->customers->findTopSpenders(3)));

        $io->section('5. ProductRepository::findInStock()');
        $io->table(['SKU', 'Name', 'Stock'], array_map(static fn (Product $p) => [
            $p->getSku(), $p->getName(), $p->getStock(),
        ], $this->products->findInStock()));

        $io->section('6. ProductRepository::findLowStock(5)');
        $io->table(['SKU', 'Name', 'Stock'], array_map(static fn (Product $p) => [
            $p->getSku(), $p->getName(), $p->getStock(),
        ], $this->products->findLowStock(5)));

        $io->section('7. ProductRepository::findBestSellers(3)');
        $io->table(['Product', 'Units sold'], array_map(static fn (array $row) => [
            $row['product']->getName(), $row['sold'],
        ], $this->products->findBestSellers(3)));

        $io->section('8. ProductRepository::rankByRevenue() (plain SQL)');
        $io->table(['Rank', 'SKU', 'Name', 'Revenue'], array_map(static fn (array $row) => [
            $row['rank'], $row['sku'], $row['name'], self::money((int) $row['revenue']),
        ], $this->products->rankByRevenue()));

        $io->section('9. OrderRepository::findByStatus(OrderStatus::Paid)');
        $io->table(['Order', 'Customer', 'Total'], array_map(static fn (Order $o) => [
            '#'.$o->getId(), $o->getCustomer()->getName(), self::money($o->getTotal()),
        ], $this->orders->findByStatus(OrderStatus::Paid)));

        if ($ann) {
            $io->section('10. OrderRepository::findForCustomerWithItems($ann)');
            foreach ($this->orders->findForCustomerWithItems($ann) as $order) {
                $items = $order->getItems();
                $io->text(\sprintf(
                    '<info>Order #%d</info> (%s, total %s), items already loaded: %s',
                    $order->getId(),
                    $order->getStatus()->value,
                    self::money($order->getTotal()),
                    $items instanceof PersistentCollection && $items->isInitialized() ? 'yes' : 'no',
                ));
                $io->listing(array_map(static fn (OrderItem $i) => \sprintf(
                    '%d × %s @ %s',
                    $i->getQuantity(),
                    $i->getProduct()->getName(),
                    self::money($i->getUnitPrice()),
                ), $items->toArray()));
            }
        }

        $io->section('11. OrderRepository::countByStatus() and totalRevenue()');
        $counts = $this->orders->countByStatus();
        $io->table(array_keys($counts), [array_values($counts)]);
        $io->text('Total revenue (paid + shipped): <info>'.self::money($this->orders->totalRevenue()).'</info>');

        return Command::SUCCESS;
    }

    private static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}

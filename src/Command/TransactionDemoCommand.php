<?php

namespace App\Command;

use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Enum\OrderStatus;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\TransactionRequiredException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A transaction groups database changes: either all of them are saved (commit) or none are (rollback).
 * Load test data first: php bin/console doctrine:fixtures:load.
 */
#[AsCommand(
    name: 'app:transaction-demo',
    description: 'Learn how database transactions work',
)]
class TransactionDemoCommand
{
    private const CUSTOMER_EMAIL = 'ann.lee@example.com';
    private const KEYBOARD_SKU = 'KB-MECH-01'; // plenty in stock
    private const DESK_MAT_SKU = 'PAD-XL';     // only 1 in stock

    // Repositories are services, so they are injected like any other dependency.
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly ProductRepository $products,
        private readonly OrderRepository $orders,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->title('Transaction demo');

        $io->section('1. OrderRepository::findByStatus(OrderStatus::Paid)');

        $orders = $this->orders->findByStatus(OrderStatus::Paid);

        $rows = [];
        foreach ($orders as $order) {
            $rows[] = [
                '#'.$order->getId(),
                $order->getCustomer()->getName(),
                self::money($order->getTotal()),
            ];
        }

        $io->table(['Order', 'Customer', 'Total'], $rows);

        $customer = $this->customers->findOneByEmail(self::CUSTOMER_EMAIL);
        $keyboard = $this->products->findOneBy(['sku' => self::KEYBOARD_SKU]);
        $deskMat = $this->products->findOneBy(['sku' => self::DESK_MAT_SKU]);
        if (null === $customer || null === $keyboard || null === $deskMat) {
            $io->error('No data. Run: php bin/console doctrine:fixtures:load');

            return Command::FAILURE;
        }

        $this->commitWithWrapInTransaction($io, $customer, $keyboard);
        $this->rollbackManually($io, $customer, $keyboard, $deskMat);
        $this->lockRowInsideTransaction($io);

        // Keep this one last: it closes the EntityManager, so it cannot be used afterwards.
        $this->failureInsideWrapInTransaction($io);

        return Command::SUCCESS;
    }

    /**
     * The usual way: wrapInTransaction() begins, flushes and commits for you.
     */
    private function commitWithWrapInTransaction(SymfonyStyle $io, Customer $customer, Product $keyboard): void
    {
        $io->section('2. Success: wrapInTransaction() commits everything');

        $stockBefore = $keyboard->getStock();
        $ordersBefore = $this->orders->count();

        // The value returned by the function is returned by wrapInTransaction() too.
        $order = $this->entityManager->wrapInTransaction(function () use ($customer, $keyboard) {
            $order = $this->createOrder($customer, $keyboard, 2);
            $keyboard->decreaseStock(2);

            return $order;
        });

        // refresh() reads the row from the database again, so this shows what was really saved.
        $this->entityManager->refresh($keyboard);

        $io->table(['', 'Before', 'After commit'], [
            ['Keyboard stock', $stockBefore, $keyboard->getStock()],
            ['Orders', $ordersBefore, $this->orders->count()],
        ]);

        // Undo the example, also in one transaction, so the test data stays the same on every run.
        $this->entityManager->wrapInTransaction(function () use ($order, $keyboard) {
            $this->entityManager->remove($order);
            $keyboard->setStock($keyboard->getStock() + 2);
        });
    }

    /**
     * The long way: you call beginTransaction(), commit() and rollback() yourself.
     * Needed when you want to keep working after a failure (see the last example).
     */
    private function rollbackManually(SymfonyStyle $io, Customer $customer, Product $keyboard, Product $deskMat): void
    {
        $io->section('3. Failure: rollback() undoes changes that were already flushed');

        $keyboardId = $keyboard->getId();
        $stockBefore = $keyboard->getStock();
        $ordersBefore = $this->orders->count();

        $this->entityManager->beginTransaction();
        try {
            // Step 1 works: flush() sends the SQL, but other connections cannot see it until commit().
            $this->createOrder($customer, $keyboard, 1);
            $keyboard->decreaseStock(1);
            $this->entityManager->flush();

            $io->listing([
                \sprintf('Inside the transaction: keyboard stock %d, orders %d', $keyboard->getStock(), $this->orders->count()),
            ]);

            // Step 2 fails: only 1 desk mat is in stock, so this throws \DomainException.
            $deskMat->decreaseStock(99);
            $this->entityManager->flush();

            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();

            // rollback() only undoes the database. Objects in memory keep their changes,
            // so clear() forgets them and the next query loads fresh data.
            $this->entityManager->clear();

            // Only the expected error is handled here. Anything else is a real problem.
            if (!$e instanceof \DomainException) {
                throw $e;
            }

            $io->text(\sprintf('Rolled back because: <comment>%s</comment>', $e->getMessage()));
        }

        $keyboard = $this->products->find($keyboardId);

        $io->table(['', 'Before', 'After rollback'], [
            ['Keyboard stock', $stockBefore, $keyboard->getStock()],
            ['Orders', $ordersBefore, $this->orders->count()],
        ]);
    }

    /**
     * SELECT ... FOR UPDATE: other transactions wanting this row wait until this one ends.
     * This stops two buyers from both taking the last item in stock.
     */
    private function lockRowInsideTransaction(SymfonyStyle $io): void
    {
        $io->section('4. Row lock: PESSIMISTIC_WRITE only works inside a transaction');

        $id = $this->products->findOneBy(['sku' => self::DESK_MAT_SKU])->getId();

        try {
            $this->products->find($id, LockMode::PESSIMISTIC_WRITE);
        } catch (TransactionRequiredException) {
            $io->text('Without a transaction: <comment>TransactionRequiredException</comment>');
        }

        $this->entityManager->wrapInTransaction(function () use ($io, $id) {
            $deskMat = $this->products->find($id, LockMode::PESSIMISTIC_WRITE);

            $io->text(\sprintf('Inside a transaction: row locked, %s has stock %d', $deskMat->getName(), $deskMat->getStock()));
            // The stock could be checked and changed safely here. The lock ends at commit or rollback.
        });
    }

    /**
     * On any exception, wrapInTransaction() rolls back, closes the EntityManager and throws the exception again.
     */
    private function failureInsideWrapInTransaction(SymfonyStyle $io): void
    {
        $io->section('5. Failure: wrapInTransaction() rolls back and closes the EntityManager');

        try {
            $this->entityManager->wrapInTransaction(function () {
                throw new \DomainException('Something went wrong');
            });
        } catch (\DomainException $e) {
            $io->text(\sprintf('Caught: <comment>%s</comment>', $e->getMessage()));
        }

        $io->text(\sprintf('EntityManager is open: <comment>%s</comment>', $this->entityManager->isOpen() ? 'yes' : 'no'));
    }

    /**
     * Creates the order and tells Doctrine to save it. The SQL runs later, on flush().
     */
    private function createOrder(Customer $customer, Product $product, int $quantity): Order
    {
        $item = new OrderItem();
        $item->setProduct($product);
        $item->setQuantity($quantity);

        $order = new Order($customer);
        $order->addItem($item);

        $this->entityManager->persist($order);

        return $order;
    }

    private static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}

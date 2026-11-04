<?php

namespace App\DataFixtures;

use App\Entity\Customer;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Enum\OrderStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Test data for local development: php bin/console doctrine:fixtures:load
 */
class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $customers = [];
        foreach ([
            'ann' => ['ann.lee@example.com', 'Ann Lee'],
            'bob' => ['bob.smith@example.com', 'Bob Smith'],
            'carla' => ['carla.diaz@example.com', 'Carla Diaz'],
            'dmytro' => ['dmytro.koval@example.com', 'Dmytro Koval'],
            'emma' => ['emma.brown@example.com', 'Emma Brown'],
            'frank' => ['frank.miller@example.com', 'Frank Miller'], // no orders
            'grace' => ['grace.chen@example.com', 'Grace Chen'],     // no orders
        ] as $key => [$email, $name]) {
            $customers[$key] = new Customer($email, $name);
            $manager->persist($customers[$key]);
        }

        $products = [];
        foreach ([
            'keyboard' => ['KB-MECH-01', 'Mechanical Keyboard', 8999, 25, "Hot-swappable switches.\nUS layout, RGB backlight."],
            'mouse' => ['MS-WL-02', 'Wireless Mouse', 2999, 3, 'Ergonomic, 2.4 GHz and Bluetooth.'],
            'monitor' => ['MON-27-4K', '27" 4K Monitor', 34900, 7, "IPS panel, 60 Hz.\nHeight-adjustable stand."],
            'cable' => ['CBL-USBC-1M', 'USB-C Cable 1 m', 799, 150, null],
            'headset' => ['HS-NC-05', 'Noise-Cancelling Headset', 14950, 0, 'Over-ear, 30 h battery.'],
            'mat' => ['PAD-XL', 'Desk Mat XL', 1999, 1, 'Water-resistant, 90 × 40 cm.'],
            'webcam' => ['CAM-1080', 'Full HD Webcam', 5499, 12, 'Built-in microphone, privacy shutter.'],
            'hub' => ['HUB-7P', '7-Port USB Hub', 3499, 40, null],
            'lamp' => ['LAMP-LED', 'LED Desk Lamp', 4299, 4, 'Dimmable, warm to cool white.'],
            'stand' => ['STD-LAP', 'Laptop Stand', 2799, 0, 'Aluminium, foldable.'],
        ] as $key => [$sku, $name, $price, $stock, $description]) {
            $products[$key] = (new Product($sku, $name, $price))
                ->setStock($stock)
                ->setDescription($description);
            $manager->persist($products[$key]);
        }

        $p = $products;
        $orders = [
            [$customers['ann'], [[$p['keyboard'], 1], [$p['mouse'], 1]], [OrderStatus::Paid, OrderStatus::Shipped]],
            [$customers['ann'], [[$p['monitor'], 2], [$p['cable'], 3]], [OrderStatus::Paid]],
            [$customers['ann'], [[$p['mat'], 1]], []],
            [$customers['bob'], [[$p['headset'], 1]], [OrderStatus::Paid, OrderStatus::Shipped]],
            [$customers['bob'], [[$p['cable'], 5], [$p['hub'], 1]], [OrderStatus::Cancelled]],
            [$customers['carla'], [[$p['monitor'], 1], [$p['webcam'], 1], [$p['keyboard'], 1]], [OrderStatus::Paid, OrderStatus::Shipped]],
            [$customers['carla'], [[$p['lamp'], 2]], [OrderStatus::Paid, OrderStatus::Cancelled]],
            [$customers['dmytro'], [[$p['cable'], 2], [$p['mouse'], 1]], [OrderStatus::Paid]],
            [$customers['dmytro'], [[$p['stand'], 1], [$p['hub'], 1]], []],
            [$customers['emma'], [[$p['webcam'], 2]], [OrderStatus::Paid, OrderStatus::Shipped]],
            [$customers['emma'], [[$p['keyboard'], 2], [$p['cable'], 4]], [OrderStatus::Paid]],
        ];

        foreach ($orders as [$customer, $lines, $statusPath]) {
            $manager->persist($this->createOrder($customer, $lines, $statusPath));
        }

        // Bulk data so the N+1 problem is visible (app:my-demo-command). Fixed seed = same data on every load.
        mt_srand(42);

        $firstNames = ['Olena', 'Taras', 'Iryna', 'Andrii', 'Sofia', 'Maksym', 'Kateryna', 'Oleh', 'Nina', 'Pavlo'];
        $lastNames = ['Shevchenko', 'Bondar', 'Melnyk', 'Kravets', 'Tkachenko', 'Moroz', 'Lysenko', 'Savchuk'];
        for ($n = 1; $n <= 60; ++$n) {
            $name = $firstNames[mt_rand(0, \count($firstNames) - 1)].' '.$lastNames[mt_rand(0, \count($lastNames) - 1)];
            $customers['bulk'.$n] = new Customer(\sprintf('customer%02d@example.com', $n), $name);
            $manager->persist($customers['bulk'.$n]);
        }

        $categories = ['Cable', 'Adapter', 'Charger', 'Speaker', 'SSD', 'Router'];
        for ($n = 1; $n <= 40; ++$n) {
            $category = $categories[$n % \count($categories)];
            $products['bulk'.$n] = (new Product(\sprintf('BULK-%03d', $n), \sprintf('%s model %d', $category, $n), mt_rand(5, 300) * 100 - 1))
                ->setStock(mt_rand(0, 100));
            $manager->persist($products['bulk'.$n]);
        }

        // frank and grace stay without orders (CustomerRepository::findWithoutOrders)
        $customerList = array_values(array_diff_key($customers, ['frank' => true, 'grace' => true]));
        $productList = array_values($products);
        $statusPaths = [[], [OrderStatus::Paid], [OrderStatus::Paid, OrderStatus::Shipped], [OrderStatus::Cancelled]];
        for ($n = 1; $n <= 300; ++$n) {
            $lines = [];
            foreach ((array) array_rand($productList, mt_rand(1, 5)) as $index) {
                $lines[] = [$productList[$index], mt_rand(1, 4)];
            }
            $customer = $customerList[mt_rand(0, \count($customerList) - 1)];
            $manager->persist($this->createOrder($customer, $lines, $statusPaths[mt_rand(0, 3)]));
        }

        // A price change after the orders above: their items keep the old unitPrice (snapshot rule).
        $p['monitor']->setPrice(32900);

        $manager->flush();
    }

    /**
     * @param list<array{Product, int}> $lines      product and quantity per order line
     * @param list<OrderStatus>         $statusPath statuses to move through, starting from "new"
     */
    private function createOrder(Customer $customer, array $lines, array $statusPath): Order
    {
        $order = new Order($customer);
        $customer->addOrder($order);

        foreach ($lines as [$product, $quantity]) {
            $order->addItem((new OrderItem())->setProduct($product)->setQuantity($quantity));
        }

        foreach ($statusPath as $status) {
            $order->setStatus($status);
        }

        return $order;
    }
}

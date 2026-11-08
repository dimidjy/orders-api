# orders-api

A learning project for exploring Symfony and Doctrine on a small online shop: customers, products and orders.

It is not a production application. The code is written to be read, with comments explaining each example.

## What it covers

- Doctrine entities and relations: `Customer`, `Product`, `Order`, `OrderItem`
- Repository queries: QueryBuilder, joins, aggregates, plain SQL with DBAL
- Avoiding the N+1 problem with fetch joins
- Transactions, rollback and row locking
- Optimistic locking with `#[ORM\Version]`
- Test data with Doctrine fixtures
- A Twig page with the latest orders

## Stack

Symfony 7.4, PHP 8.4, Doctrine ORM 3, PostgreSQL 16, Twig, Docker Compose

## Run locally

```bash
composer install
docker compose up -d
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load
symfony server:start
```

Then open the URL printed by the last command. Without the Symfony CLI, use `php -S 127.0.0.1:8000 -t public` and open http://127.0.0.1:8000.

## Demo commands

```bash
php bin/console app:repository-demo    # reading data through repositories
php bin/console app:transaction-demo   # commit, rollback and row locks
```

Adminer, a database UI, runs at http://127.0.0.1:8080.

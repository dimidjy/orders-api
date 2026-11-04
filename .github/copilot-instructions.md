# Project instructions

orders-api stores customers, products and orders for a small online shop.
Stack: Symfony 7.4, PHP 8.4, Doctrine ORM 3, PostgreSQL 16, Twig, AssetMapper (no Node/Webpack), PHPUnit.

## Structure and conventions

- Namespace `App\` maps to `src/`; the folder must match the namespace (`src/Controller` → `App\Controller`).
- Controllers (`src/Controller`): `final class … extends AbstractController`, routes as `#[Route(...)]` attributes with a `name` and `methods`, every action returns a `Response` (`$this->json()`, `$this->render()`, `$this->redirectToRoute()`).
- Dependencies come through constructor or action arguments (autowiring). Never fetch services from the container.
- Database queries live in repositories (`src/Repository`) as named methods using the QueryBuilder; controllers and commands call those methods. Raw SQL only through the DBAL connection inside a repository.
- Console commands (`src/Command`): invokable classes with `#[AsCommand]` and `__invoke(SymfonyStyle $io): int`.
- CSS goes in `assets/styles/` and is linked with `asset()`; no inline `<style>` in Twig.
- Prefer MakerBundle generators (`make:controller`, `make:entity`, `make:form`, `make:command`, `make:migration`) for new files.

## Domain rules

- Money is always an `int` in cents (USD). Never use `float` or `decimal` for amounts.
- Timestamps are `DateTimeImmutable` in UTC. `createdAt` is set by a `#[ORM\PrePersist]` callback, never by client input or a setter.
- Entity constructors take the required fields: `new Customer($email, $name)`, `new Product($sku, $name, $price)`, `new Order($customer)`.
- `Order` maps to table `orders` (`order` is a reserved word). `OrderItem::$parentOrder` uses column `order_id`.
- `OrderItem::$unitPrice` is a snapshot of the product price when the item is created; later price changes must not affect it.
- An order has at least one item; the same product appears once per order (adding it again increases the quantity).
- `OrderStatus` transitions: new → paid, new → cancelled, paid → shipped, paid → cancelled. `shipped` and `cancelled` are final.
- Customer email is unique and stored lowercase; product SKU is unique (uppercase letters, digits, `-`).

## Database and migrations

- Change the schema by editing entities, then `make:migration`; review the generated SQL. Do not edit migrations that have already been applied or pushed.
- PostgreSQL runs in Docker on `127.0.0.1:5432`, matching `DATABASE_URL` in `.env`, so `php bin/console …` and `symfony console …` both work.
- Test data: `src/DataFixtures`, loaded with `symfony console doctrine:fixtures:load` (empties the tables first; dev/test only).

## Checks before finishing a change

- `symfony console doctrine:schema:validate`
- `symfony console lint:container` and `symfony console lint:twig templates`
- `php bin/phpunit`

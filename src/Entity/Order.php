<?php

namespace App\Entity;

use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\HasLifecycleCallbacks]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false)]
    private Customer $customer;

    #[ORM\Column(enumType: OrderStatus::class)]
    private ?OrderStatus $status = OrderStatus::New;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'parentOrder', cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function setCustomer(Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getStatus(): ?OrderStatus
    {
        return $this->status;
    }

    public function setStatus(OrderStatus $status): static
    {
        if ($status !== $this->status && !$this->status->canTransitionTo($status)) {
            throw new \DomainException(\sprintf('Order status cannot change from "%s" to "%s".', $this->status->value, $status->value));
        }

        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->items->isEmpty()) {
            throw new \DomainException('An order must contain at least one item.');
        }

        $this->createdAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @return Collection<int, OrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /**
     * Adding a product that is already in the order increases that line's quantity instead.
     */
    public function addItem(OrderItem $item): static
    {
        if ($this->items->contains($item)) {
            return $this;
        }

        foreach ($this->items as $existing) {
            if ($existing->getProduct() === $item->getProduct()) {
                $existing->setQuantity($existing->getQuantity() + $item->getQuantity());

                return $this;
            }
        }

        $this->items->add($item);
        $item->setParentOrder($this);

        return $this;
    }

    public function removeItem(OrderItem $item): static
    {
        if ($this->items->removeElement($item)) {
            // set the owning side to null (unless already changed)
            if ($item->getParentOrder() === $this) {
                $item->setParentOrder(null);
            }
        }

        return $this;
    }

    /**
     * Order total in cents: sum of quantity × unitPrice over all items.
     */
    public function getTotal(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->getLineTotal();
        }

        return $total;
    }
}

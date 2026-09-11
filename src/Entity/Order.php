<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: '`order`')]
#[ORM\Index(name: 'idx_order_user_created', columns: ['user_id', 'created_at'])]
class Order
{
    public const STATUS_PAID = 'paid';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist'])]
    private Collection $items;

    #[ORM\Column]
    private int $total = 0;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PAID;

    #[ORM\Column(length: 20, unique: true)]
    private string $reference;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->items = new ArrayCollection();
        $this->reference = strtoupper(bin2hex(random_bytes(6)));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): void
    {
        $this->items->add($item);
        $this->total += $item->getSubtotal();
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getFormattedTotal(): string
    {
        return '$'.number_format($this->total / 100, 2);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'order_id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'total' => $this->total,
            'items' => array_map(static fn (OrderItem $i) => $i->toArray(), $this->items->toArray()),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}

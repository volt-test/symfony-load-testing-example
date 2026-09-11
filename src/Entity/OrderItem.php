<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'order_id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Product $product;

    #[ORM\Column]
    private int $quantity;

    /** Unit price in cents at the time of purchase */
    #[ORM\Column]
    private int $unitPrice;

    public function __construct(Order $order, Product $product, int $quantity, int $unitPrice)
    {
        $this->order = $order;
        $this->product = $product;
        $this->quantity = $quantity;
        $this->unitPrice = $unitPrice;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitPrice(): int
    {
        return $this->unitPrice;
    }

    public function getSubtotal(): int
    {
        return $this->unitPrice * $this->quantity;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'product_id' => $this->product->getId(),
            'name' => $this->product->getName(),
            'unit_price' => $this->unitPrice,
            'quantity' => $this->quantity,
            'subtotal' => $this->getSubtotal(),
        ];
    }
}

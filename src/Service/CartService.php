<?php

namespace App\Service;

use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\CartRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cart + checkout logic shared by the JSON API and the HTML pages.
 */
class CartService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CartRepository $carts,
        private readonly ProductRepository $products,
    ) {
    }

    public function getCart(User $user): Cart
    {
        return $this->carts->findForUser($user) ?? new Cart($user);
    }

    /**
     * Upserts through DBAL so two concurrent requests for the same user (two
     * tabs, a retry, an API client racing the browser) cannot trip the unique
     * constraints on cart(user_id) or cart_item(cart_id, product_id). A
     * constraint violation inside a Doctrine flush would close the
     * EntityManager, which is expensive to recover from in worker mode.
     */
    public function addItem(User $user, Product $product, int $quantity): Cart
    {
        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $conn->executeStatement(
            'INSERT INTO cart (user_id, updated_at) VALUES (:user, :now) ON CONFLICT (user_id) DO NOTHING',
            ['user' => $user->getId(), 'now' => $now]
        );
        $conn->executeStatement(
            'INSERT INTO cart_item (cart_id, product_id, quantity)
             SELECT c.id, :product, :qty FROM cart c WHERE c.user_id = :user
             ON CONFLICT (cart_id, product_id) DO UPDATE SET quantity = cart_item.quantity + EXCLUDED.quantity',
            ['product' => $product->getId(), 'qty' => $quantity, 'user' => $user->getId()]
        );
        $conn->executeStatement(
            'UPDATE cart SET updated_at = :now WHERE user_id = :user',
            ['now' => $now, 'user' => $user->getId()]
        );

        $this->em->clear(Cart::class);
        $this->em->clear(CartItem::class);

        return $this->carts->findForUser($user) ?? throw new \LogicException('Cart vanished after upsert');
    }

    /**
     * Turns the cart into a paid order inside one transaction, reserving
     * stock row by row so two concurrent checkouts cannot oversell.
     *
     * @throws EmptyCartException
     * @throws InsufficientStockException
     */
    public function checkout(User $user): Order
    {
        $cart = $this->getCart($user);
        if ($cart->isEmpty()) {
            throw new EmptyCartException();
        }

        // Lock product rows in a fixed order. Two carts holding the same products
        // in opposite order would otherwise be able to deadlock each other.
        $items = $cart->getItems()->toArray();
        usort($items, static fn (CartItem $a, CartItem $b) => $a->getProduct()->getId() <=> $b->getProduct()->getId());

        return $this->em->wrapInTransaction(function () use ($cart, $items, $user): Order {
            $order = new Order($user);

            foreach ($items as $item) {
                $product = $item->getProduct();
                if (!$this->products->reserveStock($product->getId(), $item->getQuantity())) {
                    throw new InsufficientStockException($product->getName());
                }
                $order->addItem(new OrderItem($order, $product, $item->getQuantity(), $product->getPrice()));
            }

            $this->em->persist($order);
            $cart->clear();
            $this->em->flush();

            return $order;
        });
    }
}

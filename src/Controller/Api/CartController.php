<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\ProductRepository;
use App\Service\CartService;
use App\Service\EmptyCartException;
use App\Service\InsufficientStockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1')]
final class CartController extends AbstractController
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    #[Route('/cart', name: 'api_cart', methods: ['GET'])]
    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->json(['data' => $this->cartService->getCart($user)->toArray()]);
    }

    #[Route('/cart/items', name: 'api_cart_add', methods: ['POST'])]
    public function addItem(#[MapRequestPayload] AddCartItemRequest $payload, ProductRepository $products): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $product = $products->find($payload->product_id)
            ?? throw new NotFoundHttpException(sprintf('Product %d not found', $payload->product_id));

        $cart = $this->cartService->addItem($user, $product, $payload->quantity);

        return $this->json(['data' => $cart->toArray()], Response::HTTP_CREATED);
    }

    #[Route('/checkout', name: 'api_checkout', methods: ['POST'])]
    public function checkout(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $order = $this->cartService->checkout($user);
        } catch (EmptyCartException|InsufficientStockException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return $this->json(['data' => $order->toArray()], Response::HTTP_CREATED);
    }
}

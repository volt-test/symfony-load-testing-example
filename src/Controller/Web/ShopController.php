<?php

namespace App\Controller\Web;

use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
use App\Form\AddToCartType;
use App\Repository\ProductRepository;
use App\Service\CartService;
use App\Service\EmptyCartException;
use App\Service\InsufficientStockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ShopController extends AbstractController
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(ProductRepository $products): Response
    {
        return $this->render('shop/home.html.twig', [
            'products' => $products->findFeatured(12),
        ]);
    }

    #[Route('/products', name: 'app_products', methods: ['GET'])]
    public function catalog(Request $request, ProductRepository $products): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $result = $products->findPage($page, 24);

        return $this->render('shop/catalog.html.twig', [
            'products' => $result['items'],
            'page' => $page,
            'total_pages' => (int) ceil($result['total'] / 24),
        ]);
    }

    #[Route('/products/{id}', name: 'app_product', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function product(Product $product): Response
    {
        $form = $this->createForm(AddToCartType::class, ['product_id' => $product->getId()], [
            'action' => $this->generateUrl('app_cart_add'),
        ]);

        return $this->render('shop/product.html.twig', [
            'product' => $product,
            'form' => $form,
        ]);
    }

    #[Route('/cart/add', name: 'app_cart_add', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function addToCart(Request $request, ProductRepository $products): Response
    {
        $form = $this->createForm(AddToCartType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // Symfony re-renders invalid forms with 200 by default; be explicit so
            // a load test can tell "added" (302) from "rejected" (422).
            return $this->render('shop/cart_error.html.twig', [
                'errors' => $form->getErrors(true),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $data = $form->getData();
        $product = $products->find((int) $data['product_id'])
            ?? throw new NotFoundHttpException('Product not found');

        /** @var User $user */
        $user = $this->getUser();
        $this->cartService->addItem($user, $product, (int) $data['quantity']);

        return $this->redirectToRoute('app_cart');
    }

    #[Route('/cart', name: 'app_cart', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function cart(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('shop/cart.html.twig', [
            'cart' => $this->cartService->getCart($user),
        ]);
    }

    #[Route('/checkout', name: 'app_checkout', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function checkout(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('checkout', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $order = $this->cartService->checkout($user);
        } catch (EmptyCartException|InsufficientStockException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_cart', status: Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_order', ['id' => $order->getId()]);
    }

    #[Route('/orders/{id}', name: 'app_order', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function order(Order $order): Response
    {
        if ($order->getUser()->getId() !== $this->getUser()?->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('shop/order.html.twig', ['order' => $order]);
    }
}

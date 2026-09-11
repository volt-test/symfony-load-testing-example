<?php

namespace App\Controller\Api;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/products')]
final class ProductController extends AbstractController
{
    #[Route('', name: 'api_products', methods: ['GET'])]
    public function index(Request $request, ProductRepository $products): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = min(50, max(1, $request->query->getInt('per_page', 12)));

        $result = $products->findPage($page, $perPage);

        return $this->json([
            'data' => array_map(static fn (Product $p) => $p->toArray(), $result['items']),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    #[Route('/{id}', name: 'api_product', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Product $product): JsonResponse
    {
        return $this->json(['data' => $product->toArray()]);
    }
}

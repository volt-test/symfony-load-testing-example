<?php

namespace App\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AddCartItemRequest
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\Positive]
        public int $product_id,
        #[Assert\Range(min: 1, max: 10)]
        public int $quantity = 1,
    ) {
    }
}

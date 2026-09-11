<?php

namespace App\Service;

class InsufficientStockException extends \RuntimeException
{
    public function __construct(public readonly string $productName)
    {
        parent::__construct(sprintf('Not enough stock for "%s"', $productName));
    }
}

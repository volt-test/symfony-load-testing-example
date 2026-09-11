<?php

namespace App\Service;

class EmptyCartException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Your cart is empty');
    }
}

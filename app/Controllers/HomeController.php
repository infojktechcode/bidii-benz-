<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;

final class HomeController
{
    public function index(array $params = []): void
    {
        View::render('home', [
            'title' => 'Bidii Benz Rentals — Mercedes-Benz Car Hire, Kitengela',
        ]);
    }
}

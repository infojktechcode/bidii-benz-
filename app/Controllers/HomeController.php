<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\View;
use App\Repositories\CarRepository;

final class HomeController
{
    private CarRepository $cars;

    public function __construct()
    {
        $this->cars = new CarRepository(Database::connect(Config::load(dirname(__DIR__, 2))));
    }

    public function index(array $params = []): void
    {
        View::render('home', [
            'title' => 'Bidii Benz Rentals — Mercedes-Benz Car Hire, Kitengela',
            'featured' => array_slice($this->cars->findActive(), 0, 4),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\View;
use App\Repositories\CarRepository;
use App\Core\Database;
use App\Core\Input;
use App\Services\PhotoStorage;

/**
 * Vehicle browsing for clients.
 */
final class CarController
{
    private CarRepository $cars;
    private Config $config;

    public function __construct()
    {
        $this->config = Config::load(dirname(__DIR__, 2));
        $this->cars = new CarRepository(Database::connect($this->config));
    }

    public function index(array $params = []): void
    {
        $cars = $this->cars->findActive();
        View::render('cars/index', [
            'title' => 'Our Fleet — Bidii Benz Rentals',
            'cars' => $cars,
            'availability' => $this->cars->occupiedUntil(date('Y-m-d')),
        ]);
    }

    /**
     * Serve a stored vehicle photo.
     *
     * Files live outside the web root; the name is resolved from the database
     * row, so no user input ever reaches the filesystem path. The route takes
     * only the car id — there is no filename parameter to traverse.
     */
    public function media(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        $car = $id > 0 ? $this->cars->findById($id) : null;
        if ($car === null || !is_string($car['image_path']) || $car['image_path'] === '') {
            http_response_code(404);
            exit;
        }

        $dir = (string) $this->config->get('uploads.dir') . DIRECTORY_SEPARATOR . 'cars';
        $original = $dir . DIRECTORY_SEPARATOR . basename($car['image_path']);
        // Serve the generated 800px derivative when present; fall back to the original.
        $thumb = $dir . DIRECTORY_SEPARATOR . PhotoStorage::thumbName(basename($car['image_path']));
        $path = is_file($thumb) ? $thumb : $original;
        if (!is_file($path)) {
            http_response_code(404);
            exit;
        }

        $info = @getimagesize($path);
        if ($info === false) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . $info['mime']);
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');
        readfile($path);
        exit;
    }

    public function detail(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id < 1) {
            http_response_code(404);
            View::render('errors/404', [], 404);
            return;
        }

        $car = $this->cars->findById($id);
        if ($car === null) {
            http_response_code(404);
            View::render('errors/404', [], 404);
            return;
        }

        // Get occupied days for next 60 days for calendar
        $start = date('Y-m-d');
        $end = date('Y-m-d', strtotime('+60 days'));
        $occupied = $this->cars->getOccupiedDays($id, $start, $end);

        View::render('cars/detail', [
            'title' => $car['make'] . ' ' . $car['model'] . ' — Bidii Benz Rentals',
            'car' => $car,
            'occupied_days' => $occupied,
            'min_date' => $start,
            'max_date' => $end,
        ]);
    }
}
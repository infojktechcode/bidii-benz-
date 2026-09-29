<?php
/** @var string $title */
$nonceTitle = $title ?? 'Bidii Benz Rentals';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="index, follow">
<title><?= \App\Core\View::e($nonceTitle) ?></title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<header class="site-header">
  <div class="container">
    <a class="brand" href="<?= \App\Core\View::e($basePath ?? '/') ?>">BIDII BENZ <span>RENTALS</span></a>
    <nav aria-label="Main navigation">
      <a href="/">Home</a>
      <a href="/cars">Cars</a>
      <a href="/login">Sign in</a>
      <a href="/register">Register</a>
    </nav>
  </div>
</header>
<main class="container">

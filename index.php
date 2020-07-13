<?php

require __DIR__ . '/src/Searcher.php';

$config = parse_ini_file(__DIR__ . '/config.ini');

$connection = new mysqli(
    $config['host'],
    $config['user'],
    $config['password'],
    $config['database']
);
$searcher = new App\Searcher($connection);

$searchString = !empty($_GET['search']) ? $_GET['search'] : '';
$filters['statuses'] = !empty($_GET['statuses']) ? $_GET['statuses'] : [];
$res = $searcher->search($searchString, $filters);
echo json_encode($res);

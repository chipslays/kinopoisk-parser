<?php 

date_default_timezone_set('Europe/Samara');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Client.php';

use Kinopoisk\Client;

$client = new Client();

// var_dump($client->getFilmById(961715, 10)); //deadpool
$data = $client->getFilmById(3212, 10);
// print_r($client->getFilmById(859919, 10));

// print_r($client->getFactsByFilmId(961715));

print_r($data);
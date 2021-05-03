<?php

/**
 * Application route setup
 *
 * $app, $container and $router global variables available in here by default
 *
 * @author Benjamin Ulmer
 * @link http://github.com/remluben/slim-boilerplate
 */

// Defining a route using a controller
$router->apiPrefix = '/api/v1';

$router->get('/', 'HomeController::index')->name('index');
$router->post('/', 'HomeController::subscribe')->name('subscribe');

// Geocoding
$router->get('/geocoding', 'GeocodeController::index')->name('geocodingIndex');
$router->post('/geocoding', 'GeocodeController::post')->name('geocodingPost');


// Using a route with a callback function
$router->get('/terms', function () use ($app) {
    $app->render('terms.twig');
})->name('terms');
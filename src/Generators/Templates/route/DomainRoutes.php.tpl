<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */

$routes->group('{domainKebab}', ['namespace' => '{controllersNs}'], function ($routes) {

    // Auth & Admin Protected Group
    $routes->group('', ['filter' => {filtersList}], function ($routes) {
        // Resource routes will be injected here
    });
});

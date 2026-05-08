        // {resource} Routes
        $routes->get('{route}', '{controller}::index');
        $routes->get('{route}/(:num)', '{controller}::show/$1');
        $routes->post('{route}', '{controller}::create');
        $routes->put('{route}/(:num)', '{controller}::update/$1');
        $routes->delete('{route}/(:num)', '{controller}::delete/$1');


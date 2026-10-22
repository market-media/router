<?php

namespace Http;

use MarketMedia\Router\Router;

class User
{
    public function handle(Router $router): bool
    {
        // Set user_id only after successful authentication on the server.
        return isset($_SESSION['user_id']);
    }
}

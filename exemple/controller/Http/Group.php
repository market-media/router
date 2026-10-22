<?php

namespace Http;

class Group
{
    public function handle(): bool
    {
        return isset($_SESSION['user_id']) && ($_SESSION['role'] ?? null) === 'admin';
    }
}

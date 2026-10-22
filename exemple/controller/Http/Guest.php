<?php

namespace Http;

class Guest
{
    public function handle(): bool
    {
        return !isset($_SESSION['user_id']);
    }
}

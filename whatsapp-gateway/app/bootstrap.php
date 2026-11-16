<?php

declare(strict_types=1);

use App\Config\Config;
use App\Database\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

// Vérification de la configuration indispensable.
Config::require('APP_ENV');
Config::require('APP_URL');

// Vérification de la disponibilité de la base de données.
Database::getPdo();

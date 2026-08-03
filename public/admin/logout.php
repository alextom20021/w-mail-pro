<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use MailAI\Core\SessionAuth;

SessionAuth::logout();
header('Location: /admin/login.php');

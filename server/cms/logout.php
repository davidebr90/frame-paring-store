<?php
declare(strict_types=1);

require_once __DIR__ . '/_cms.php';

logout_user();
redirect('login.php');

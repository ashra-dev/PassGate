<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/functions.php';

clearCustomerSession();
safeRedirect('index.php');

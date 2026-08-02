<?php

declare(strict_types=1);

session_start();
session_unset();
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Logging out…</title>
</head>
<body>
<script>
    localStorage.setItem('passgate_auth', 'logout');
    window.location.replace('index.php');
</script>
</body>
</html>

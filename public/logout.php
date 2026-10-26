<?php
require __DIR__ . '/../src/bootstrap.php'; startApp(); $_SESSION = []; session_destroy(); redirect('/login.php');

<?php
declare(strict_types=1);

/**
 * OpenShare Development Router
 * Phục vụ Clean URLs (không hiển thị phần mở rộng .php) trên máy chủ nội bộ PHP CLI
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicDir = __DIR__ . '/public';
$filePath = $publicDir . $uri;

// 1. Phục vụ trực tiếp tệp tĩnh nếu tồn tại (CSS, JS, Fonts, Ảnh)
if ($uri !== '/' && is_file($filePath)) {
    return false;
}

// 2. Nếu đường dẫn tương ứng với tệp .php trong public/ (ví dụ /shared -> /public/shared.php)
$cleanPath = rtrim($uri, '/');
$phpFile = $publicDir . $cleanPath . '.php';
if ($cleanPath !== '' && is_file($phpFile)) {
    require $phpFile;
    return true;
}

// 3. Trang chủ /
if ($uri === '/' || $uri === '') {
    require $publicDir . '/index.php';
    return true;
}

// 4. Nếu tệp có đuôi .php được gọi trực tiếp (ví dụ /shared.php)
if (str_ends_with($uri, '.php') && is_file($publicDir . $uri)) {
    require $publicDir . $uri;
    return true;
}

// 5. Fallback về index.php
require $publicDir . '/index.php';
return true;


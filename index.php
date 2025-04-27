<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'logs/php_errors.log');

require_once 'core/helpers.php';

// Bắt OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    http_response_code(200);
    exit;
}

// Kiểm tra trạng thái cài đặt
$installedFile = 'config/installed.php';
if (!file_exists($installedFile)) {
    header('Location: ' . getBasePath() . 'install/index.php');
    exit;
}

// Xử lý request
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Chuẩn hóa URI
$basePath = parse_url(getBasePath(), PHP_URL_PATH); // Chỉ lấy phần path
error_log("BasePath: $basePath");
$requestUri = str_replace($basePath, '', $requestUri);
$requestUri = '/' . ltrim($requestUri, '/'); // Đảm bảo có dấu /
error_log("Normalized Request URI: $requestUri");

// Nếu là request API (/api/v1/...)
if (preg_match('#^/api/v1/#', $requestUri)) {
    error_log("API route matched: $requestMethod $requestUri");
    require_once 'core/router.php';
    try {
        handleApiRequest($requestMethod, $requestUri);
    } catch (Exception $e) {
        http_response_code(500);
        $message = 'Lỗi hệ thống: ' . htmlspecialchars($e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $message]);
        logError('Lỗi xử lý API: ' . $e->getMessage());
    }
    exit;
}else {
    error_log("API route NOT matched: $requestMethod $requestUri");
}

// Phục vụ ReactJS bundle
$reactIndex = 'dist/index.html';
if (file_exists($reactIndex)) {
    header('Content-Type: text/html');
    $content = file_get_contents($reactIndex);
    $baseUrl = rtrim(getBasePath(), '/') . '/api/v1/';
    $rootUrl = rtrim(getBasePath(), '/');
    $script = "<script>window.APP_CONFIG = { baseUrl: '$baseUrl', rootUrl: '$rootUrl' };</script>";
    $content = str_replace('</head>', $script . '</head>', $content);
    echo $content;
} else {
    http_response_code(404);
    echo 'Không tìm thấy bundle ReactJS. Vui lòng build và copy vào thư mục dist/.';
    logError('Không tìm thấy file dist/index.html');
}
?>
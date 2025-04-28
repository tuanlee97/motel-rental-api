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
//error_log("BasePath: $basePath");
$requestUri = str_replace($basePath, '', $requestUri);
$requestUri = '/' . ltrim($requestUri, '/'); // Đảm bảo có dấu /
//error_log("Normalized Request URI: $requestUri");

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
    logError('Không tìm thấy file dist/index.html');
       echo <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Ứng dụng chưa sẵn sàng</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', sans-serif;
            background-color: #f9fafb;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .container {
            text-align: center;
            max-width: 600px;
            padding: 20px;
        }
        h1 {
            font-size: 42px;
            color: #ff6f00;
            margin-bottom: 10px;
        }
        p {
            font-size: 18px;
            margin-top: 0;
        }
        .notice {
            margin-top: 20px;
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
        }
        .button {
            margin-top: 30px;
            display: inline-block;
            background-color: #1976d2;
            color: white;
            padding: 12px 24px;
            border-radius: 4px;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }
        .button:hover {
            background-color: #1565c0;
        }
        small {
            display: block;
            margin-top: 20px;
            color: #888;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚧 Ứng dụng chưa sẵn sàng</h1>
        <p>Có vẻ như giao diện của ứng dụng hiện tại chưa thể phục vụ.</p>
        <div class="notice">
            Vui lòng liên hệ <strong>nhà phát triển</strong> để hoàn tất quá trình cài đặt.
        </div>
    </div>
</body>
</html>
HTML;
}
?>
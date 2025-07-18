<?php
    function removeUtf8mb4($string) {
        // Loại bỏ tất cả ký tự UTF-8 4 byte (emoji, symbol đặc biệt)
        return preg_replace('/[\xF0-\xF7][\x80-\xBF]{3}/', '', $string);
    }

    function getBasePath() {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        // Loại bỏ '/install' nếu có
        $baseDir = preg_replace('#/install$#', '', $scriptDir);
        $basePath = 'http://' . $_SERVER['HTTP_HOST'] . $baseDir . '/';
        return $basePath;
    }

    function logError($message) {
        $logFile = __DIR__ . '/../logs/api.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    }

    function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map('sanitizeInput', $input);
        }
        $input = trim((string)$input);
        // Lưu bản gốc trước khi lọc
        $original = $input;

        // Loại bỏ emoji (ký tự 4-byte)
        $input = removeUtf8mb4($input);

        // Gán cờ nếu có sự thay đổi
        if ($original !== $input) {
            $emojiRemoved = true;
            responseJson(['status' => 'error', 'message' => 'Một số ký tự không hợp lệ (emoji)'], 400);
        }
        // Xử lý HTML đặc biệt
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');

        // if (!$input) {
        //     responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
        // }
        return $input;
    }

    function responseJson($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    function getClientIp() {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    function checkRateLimit($ip) {
        $cacheFile = __DIR__ . '/../cache/rate_limit.json';
        $limit = 1000; // 100 request/giờ
        $window = 3600; // 1 giờ

        $data = file_exists($cacheFile) ? json_decode(file_get_contents($cacheFile), true) : [];
        $currentTime = time();

        if (!isset($data[$ip]) || $data[$ip]['reset'] < $currentTime) {
            $data[$ip] = ['count' => 0, 'reset' => $currentTime + $window];
        }

        if ($data[$ip]['count'] >= $limit) {
            responseJson(['status' => 'error', 'message' => 'Vượt quá giới hạn yêu cầu. Vui lòng thử lại sau.'], 429);
        }

        $data[$ip]['count']++;
        file_put_contents($cacheFile, json_encode($data));
    }
    function validateOutRange($value, $case = 'Giá trị') {
        if (!is_numeric($value)) {
            responseJson(['status' => 'error', 'message' => "$case phải là một số hợp lệ"], 400);
        }

        $value = round(floatval($value), 2);

        if ($value < 0 || $value > 99999999.99) {
            responseJson(['status' => 'error', 'message' => "Giá trị $case vượt quá giới hạn cho phép (tối đa 99,999,999.99)"], 400);
        }

        return $value;
    }
    /**
    *    Chuẩn hóa ngày (YYYY-MM-DD) thành timestamp đầy đủ (YYYY-MM-DD HH:MM:SS)
    *    @param string|null $dateString - Ngày ở dạng 'YYYY-MM-DD' hoặc null
    *    @param bool $useCurrentTime - true: dùng giờ hiện tại; false: dùng '00:00:00'
    *    @return string - Ngày giờ chuẩn 'Y-m-d H:i:s'
    *    @throws Exception - Nếu định dạng sai
    */
    function normalizeDateToTimestamp(?string $dateString, bool $useCurrentTime = true): string {
        if (!$dateString) {
            // Không truyền thì dùng ngày hiện tại
            $dateString = date('Y-m-d');
        }

        // Kiểm tra định dạng YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            responseJson(['status' => 'error', 'message' => "Ngày không hợp lệ, phải theo định dạng YYYY-MM-DD"], 400);
            throw new Exception("Ngày không hợp lệ, phải theo định dạng YYYY-MM-DD");
        }

        $timePart = $useCurrentTime ? date('H:i:s') : '00:00:00';
        return $dateString . ' ' . $timePart;
    }
?>
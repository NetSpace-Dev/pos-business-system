<?php
namespace App\Utils;

class Response {
    public static function json($data, int $statusCode = 200) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $statusCode = 500, array $additional = []) {
        $response = ['error' => $message];
        if (!empty($additional)) {
            $response = array_merge($response, $additional);
        }
        self::json($response, $statusCode);
    }
}

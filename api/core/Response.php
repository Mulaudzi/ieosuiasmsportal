<?php
/**
 * JSON Response Helper
 */

class Response {
    public static function json($data, int $status = 200): void {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    public static function success($data = null, int $status = 200): void {
        $response = ['success' => true];
        if ($data !== null) {
            if (is_array($data) && !isset($data[0])) {
                $response = array_merge($response, $data);
            } else {
                $response['data'] = $data;
            }
        }
        self::json($response, $status);
    }
    
    public static function error(string $message, int $status = 400, array $errors = []): void {
        $response = [
            'success' => false,
            'message' => $message,
        ];
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }
        self::json($response, $status);
    }
    
    public static function paginate(array $data, int $total, int $page, int $perPage): void {
        self::json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
            ]
        ]);
    }
    
    public static function created(array $data): void {
        self::success($data, 201);
    }
    
    public static function noContent(): void {
        http_response_code(204);
        exit;
    }
}

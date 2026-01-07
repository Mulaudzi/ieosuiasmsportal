<?php
/**
 * Request Helper
 */

class Request {
    private static $input = null;
    
    public static function input(?string $key = null, $default = null) {
        if (self::$input === null) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            
            if (strpos($contentType, 'application/json') !== false) {
                $raw = file_get_contents('php://input');
                self::$input = json_decode($raw, true) ?? [];
            } elseif (strpos($contentType, 'multipart/form-data') !== false) {
                self::$input = $_POST;
            } else {
                self::$input = array_merge($_GET, $_POST);
                $raw = file_get_contents('php://input');
                if ($raw) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        self::$input = array_merge(self::$input, $decoded);
                    }
                }
            }
        }
        
        if ($key === null) {
            return self::$input;
        }
        
        return self::$input[$key] ?? $default;
    }
    
    public static function query(?string $key = null, $default = null) {
        if ($key === null) {
            return $_GET;
        }
        return $_GET[$key] ?? $default;
    }
    
    public static function file(string $key): ?array {
        return $_FILES[$key] ?? null;
    }
    
    public static function has(string $key): bool {
        return isset(self::input()[$key]);
    }
    
    public static function only(array $keys): array {
        $input = self::input();
        return array_intersect_key($input, array_flip($keys));
    }
    
    public static function validate(array $rules): array {
        $input = self::input();
        $errors = [];
        $validated = [];
        
        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $value = $input[$field] ?? null;
            
            foreach ($ruleList as $rule) {
                $params = [];
                if (strpos($rule, ':') !== false) {
                    list($rule, $paramString) = explode(':', $rule, 2);
                    $params = explode(',', $paramString);
                }
                
                $error = self::validateRule($field, $value, $rule, $params, $input);
                if ($error) {
                    $errors[$field][] = $error;
                }
            }
            
            if (!isset($errors[$field]) && $value !== null) {
                $validated[$field] = $value;
            }
        }
        
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }
        
        return $validated;
    }
    
    private static function validateRule(string $field, $value, string $rule, array $params, array $input): ?string {
        $label = ucfirst(str_replace('_', ' ', $field));
        
        switch ($rule) {
            case 'required':
                if ($value === null || $value === '') {
                    return "$label is required";
                }
                break;
                
            case 'email':
                if ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return "$label must be a valid email";
                }
                break;
                
            case 'min':
                $min = (int) $params[0];
                if (is_string($value) && strlen($value) < $min) {
                    return "$label must be at least $min characters";
                }
                if (is_numeric($value) && $value < $min) {
                    return "$label must be at least $min";
                }
                break;
                
            case 'max':
                $max = (int) $params[0];
                if (is_string($value) && strlen($value) > $max) {
                    return "$label must not exceed $max characters";
                }
                if (is_numeric($value) && $value > $max) {
                    return "$label must not exceed $max";
                }
                break;
                
            case 'numeric':
                if ($value && !is_numeric($value)) {
                    return "$label must be a number";
                }
                break;
                
            case 'array':
                if ($value && !is_array($value)) {
                    return "$label must be an array";
                }
                break;
                
            case 'in':
                if ($value && !in_array($value, $params)) {
                    return "$label must be one of: " . implode(', ', $params);
                }
                break;
                
            case 'confirmed':
                $confirmField = $field . '_confirmation';
                if ($value !== ($input[$confirmField] ?? null)) {
                    return "$label confirmation does not match";
                }
                break;
                
            case 'unique':
                $table = $params[0];
                $column = $params[1] ?? $field;
                $exceptId = $params[2] ?? null;
                
                $query = table($table)->where($column, $value);
                if ($exceptId) {
                    $query->where('id', '!=', $exceptId);
                }
                if ($query->first()) {
                    return "$label already exists";
                }
                break;
                
            case 'exists':
                $table = $params[0];
                $column = $params[1] ?? 'id';
                if ($value && !table($table)->where($column, $value)->first()) {
                    return "$label does not exist";
                }
                break;
        }
        
        return null;
    }
    
    public static function bearerToken(): ?string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

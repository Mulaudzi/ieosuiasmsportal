<?php
/**
 * Settings Controller
 */

class SettingsController {
    public function smsPreferences(): void {
        require_once __DIR__.'/../domain/SmsOptOut.php';
        Response::success(['opt_out_text'=>SmsOptOut::text((int)Auth::id())]);
    }

    public function updateSmsPreferences(): void {
        $input=Request::input();$text=$input['opt_out_text']??null;
        if(!is_string($text)||mb_strlen(trim($text))>500){Response::error('Opt-out text must be at most 500 characters',422);return;}
        $text=trim($text);
        if($text===''){Response::error('Opt-out text cannot be empty',422);return;}
        require_once __DIR__.'/../domain/TemplatePersonalizer.php';
        try{TemplatePersonalizer::assertSupported($text);}catch(InvalidArgumentException $e){Response::error($e->getMessage(),422);return;}
        $q=db()->prepare('INSERT INTO sms_preferences (user_id,opt_out_text,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE opt_out_text=VALUES(opt_out_text),updated_at=NOW()');
        $q->execute([(int)Auth::id(),$text]);
        Response::success(['opt_out_text'=>$text]);
    }

    public function profile(): void {
        $user = Auth::user();
        $account = table('accounts')->where('user_id', $user['id'])->first();
        
        Response::success([
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'avatar_url' => Auth::formatUserForFrontend($user)['avatar_url'],
            ],
            'account' => $account,
        ]);
    }
    
    public function updateProfile(): void {
        $user = Auth::user();
        
        $data = Request::validate([
            'name' => 'max:100',
            'phone' => 'max:20',
            'company_name' => 'max:100',
            'industry' => 'max:100',
            'address' => 'max:255',
            'city' => 'max:100',
            'province' => 'max:100',
            'postal_code' => 'max:20',
            'country' => 'max:100',
            'vat_number' => 'max:50',
            'website' => 'max:255',
        ]);
        
        // Update user
        $userData = [];
        if (isset($data['name'])) $userData['name'] = $data['name'];
        if (isset($data['phone'])) $userData['phone'] = $data['phone'];
        
        if (!empty($userData)) {
            $userData['updated_at'] = date('Y-m-d H:i:s');
            table('users')->where('id', $user['id'])->update($userData);
        }
        
        // Update or create account
        $accountData = array_intersect_key($data, array_flip([
            'company_name', 'address', 'city', 'province', 
            'postal_code', 'country', 'vat_number', 'website', 'industry'
        ]));
        
        if (!empty($accountData)) {
            $account = table('accounts')->where('user_id', $user['id'])->first();
            
            if ($account) {
                $accountData['updated_at'] = date('Y-m-d H:i:s');
                table('accounts')->where('id', $account['id'])->update($accountData);
            } else {
                $accountData['user_id'] = $user['id'];
                $accountData['created_at'] = date('Y-m-d H:i:s');
                $accountData['updated_at'] = date('Y-m-d H:i:s');
                table('accounts')->insert($accountData);
            }
        }
        
        // Fetch updated data
        $user = table('users')->where('id', $user['id'])->first();
        $account = table('accounts')->where('user_id', $user['id'])->first();
        
        Response::success([
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
            ],
            'account' => $account,
            'message' => 'Profile updated successfully',
        ]);
    }
    
    public function uploadBranding(): void {
        $file = Request::file('logo');
        
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            Response::error('No file uploaded', 400);
        }
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            Response::error('Invalid file type. Allowed: JPG, PNG, GIF, WebP', 400);
        }
        
        // Validate file size (max 2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            Response::error('File too large. Maximum size: 2MB', 400);
        }
        
        // Create uploads directory
        $uploadsDir = __DIR__ . '/../uploads/logos';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = Auth::id() . '_' . time() . '.' . $extension;
        $filepath = $uploadsDir . '/' . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            Response::error('Failed to save file', 500);
        }
        
        // Update account
        $logoUrl = env('APP_URL') . '/uploads/logos/' . $filename;
        
        $account = table('accounts')->where('user_id', Auth::id())->first();
        
        if ($account) {
            table('accounts')->where('id', $account['id'])->update([
                'logo_url' => $logoUrl,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            table('accounts')->insert([
                'user_id' => Auth::id(),
                'logo_url' => $logoUrl,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        Response::success([
            'logo_url' => $logoUrl,
            'message' => 'Logo uploaded successfully',
        ]);
    }
    
    public function updatePassword(): void {
        Response::error('Passwords are managed at https://auth.ieosuia.com/forgot-password. Use central IEOSUIA authentication to reset your password.',409);
        return;
        $data = Request::validate([
            'current_password' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);
        
        $user = Auth::user();
        
        if (!password_verify($data['current_password'], $user['password'])) {
            Response::error('Current password is incorrect', 400);
        }
        
        table('users')->where('id', $user['id'])->update([
            'password' => Auth::hashPassword($data['password']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Response::success(['message' => 'Password updated successfully']);
    }
}

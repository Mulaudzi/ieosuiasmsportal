<?php
/**
 * Template Controller
 */

class TemplateController {
    public function index(): void {
        $userId = Auth::id();
        $type = Request::query('type'); // sms or email
        
        $query = table('templates')->where('user_id', $userId);
        
        if ($type) {
            $query->where('type', $type);
        }
        
        $templates = $query->orderBy('created_at', 'DESC')->get();
        
        Response::success(['templates' => $templates]);
    }
    
    public function store(): void {
        $data = Request::validate([
            'name' => 'required|max:100',
            'type' => 'required|in:sms,email',
            'content' => 'required|max:10000',
            'subject' => 'max:255',
        ]);
        
        try {
            $templateId = table('templates')->insert([
                'user_id' => Auth::id(),
                'name' => $data['name'],
                'type' => $data['type'],
                'content' => $data['content'],
                'subject' => $data['subject'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            
            $template = table('templates')->where('id', $templateId)->first();
            Response::created(['template' => $template]);
        } catch (Exception $e) {
            error_log('Template store error: ' . $e->getMessage());
            Response::error('Failed to create template', 500);
        }
    }
    
    public function show(array $params): void {
        // Validate ID is numeric
        if (!is_numeric($params['id'])) {
            Response::error('Invalid template ID', 400);
        }
        
        $template = table('templates')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$template) {
            Response::error('Template not found', 404);
        }
        
        Response::success(['template' => $template]);
    }
    
    public function update(array $params): void {
        // Validate ID is numeric
        if (!is_numeric($params['id'])) {
            Response::error('Invalid template ID', 400);
        }
        
        $template = table('templates')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$template) {
            Response::error('Template not found', 404);
        }
        
        $data = Request::validate([
            'name' => 'max:100',
            'content' => 'max:10000',
            'subject' => 'max:255',
        ]);
        
        // Filter out empty values but keep valid updates
        $updateData = array_filter($data, function($value) {
            return $value !== null && $value !== '';
        });
        
        // Require at least one field to update
        if (empty($updateData)) {
            Response::error('No changes provided', 400);
        }
        
        // Prevent type changes (type is immutable after creation)
        if (isset($updateData['type'])) {
            unset($updateData['type']);
        }
        
        $updateData['updated_at'] = date('Y-m-d H:i:s');
        
        try {
            table('templates')->where('id', $params['id'])->update($updateData);
            
            $template = table('templates')->where('id', $params['id'])->first();
            Response::success(['template' => $template]);
        } catch (Exception $e) {
            error_log('Template update error: ' . $e->getMessage());
            Response::error('Failed to update template', 500);
        }
    }
    
    public function destroy(array $params): void {
        // Validate ID is numeric
        if (!is_numeric($params['id'])) {
            Response::error('Invalid template ID', 400);
        }
        
        $template = table('templates')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$template) {
            Response::error('Template not found', 404);
        }
        
        try {
            table('templates')->where('id', $params['id'])->delete();
            Response::noContent();
        } catch (Exception $e) {
            error_log('Template delete error: ' . $e->getMessage());
            Response::error('Failed to delete template', 500);
        }
    }
    
    /**
     * Bulk delete templates
     */
    public function bulkDelete(): void {
        $data = Request::validate([
            'ids' => 'required',
        ]);
        
        $ids = $data['ids'];
        if (!is_array($ids) || empty($ids)) {
            Response::error('Invalid template IDs', 400);
        }
        
        // Validate all IDs are numeric
        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                Response::error('Invalid template ID in list', 400);
            }
        }
        
        $userId = Auth::id();
        $deleted = 0;
        
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            foreach ($ids as $id) {
                $template = table('templates')
                    ->where('id', $id)
                    ->where('user_id', $userId)
                    ->first();
                    
                if ($template) {
                    table('templates')->where('id', $id)->delete();
                    $deleted++;
                }
            }
            
            $pdo->commit();
            Response::success(['deleted' => $deleted]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('Template bulk delete error: ' . $e->getMessage());
            Response::error('Failed to delete templates', 500);
        }
    }
}

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
            'content' => 'required',
            'subject' => 'max:255',
        ]);
        
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
    }
    
    public function show(array $params): void {
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
        $template = table('templates')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$template) {
            Response::error('Template not found', 404);
        }
        
        $data = Request::validate([
            'name' => 'max:100',
            'content' => '',
            'subject' => 'max:255',
        ]);
        
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        table('templates')->where('id', $params['id'])->update($data);
        
        $template = table('templates')->where('id', $params['id'])->first();
        Response::success(['template' => $template]);
    }
    
    public function destroy(array $params): void {
        $template = table('templates')
            ->where('id', $params['id'])
            ->where('user_id', Auth::id())
            ->first();
        
        if (!$template) {
            Response::error('Template not found', 404);
        }
        
        table('templates')->where('id', $params['id'])->delete();
        
        Response::noContent();
    }
}

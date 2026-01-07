<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TemplateController extends Controller
{
    /**
     * List all templates for the user
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $channel = $request->query('channel');
        $category = $request->query('category');

        $query = Template::forUser($user->id);

        if ($channel) {
            $query->where('channel', $channel);
        }

        if ($category) {
            $query->where('category', $category);
        }

        $templates = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $templates->map(function ($template) {
                return [
                    'id' => $template->id,
                    'name' => $template->name,
                    'channel' => $template->channel,
                    'content' => $template->content,
                    'subject' => $template->subject,
                    'variables' => $template->variables ?? $template->extractVariables(),
                    'category' => $template->category,
                    'isDefault' => $template->is_default,
                    'createdAt' => $template->created_at->toISOString(),
                    'updatedAt' => $template->updated_at->toISOString(),
                ];
            }),
        ]);
    }

    /**
     * Create a new template
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'in:sms,email'],
            'content' => ['required', 'string', 'max:10000'],
            'subject' => ['required_if:channel,email', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:100'],
            'variables' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        // Check for duplicate name
        $exists = Template::forUser($user->id)
            ->where('name', $request->input('name'))
            ->where('channel', $request->input('channel'))
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'error' => 'A template with this name already exists for this channel.',
            ], 422);
        }

        $template = Template::create([
            'user_id' => $user->id,
            'name' => $request->input('name'),
            'channel' => $request->input('channel'),
            'content' => $request->input('content'),
            'subject' => $request->input('subject'),
            'category' => $request->input('category', 'General'),
            'variables' => $request->input('variables'),
            'is_default' => false,
        ]);

        // Extract variables if not provided
        if (!$template->variables) {
            $template->update(['variables' => $template->extractVariables()]);
        }

        AuditLog::log($user->id, 'create', 'template', $template->id, [], $template->toArray());

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $template->id,
                'name' => $template->name,
                'channel' => $template->channel,
                'content' => $template->content,
                'subject' => $template->subject,
                'variables' => $template->variables,
                'category' => $template->category,
            ],
            'message' => 'Template created successfully',
        ], 201);
    }

    /**
     * Get a single template
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $template = Template::forUser($user->id)->find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'error' => 'Template not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $template->id,
                'name' => $template->name,
                'channel' => $template->channel,
                'content' => $template->content,
                'subject' => $template->subject,
                'variables' => $template->variables ?? $template->extractVariables(),
                'category' => $template->category,
                'isDefault' => $template->is_default,
                'usageCount' => $template->campaigns()->count(),
                'createdAt' => $template->created_at->toISOString(),
                'updatedAt' => $template->updated_at->toISOString(),
            ],
        ]);
    }

    /**
     * Update a template
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'string', 'max:10000'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:100'],
            'variables' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        $template = Template::forUser($user->id)->find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'error' => 'Template not found',
            ], 404);
        }

        $oldValues = $template->toArray();

        // Check for duplicate name if changing name
        if ($request->has('name') && $request->input('name') !== $template->name) {
            $exists = Template::forUser($user->id)
                ->where('name', $request->input('name'))
                ->where('channel', $template->channel)
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'error' => 'A template with this name already exists.',
                ], 422);
            }
        }

        $template->update($request->only(['name', 'content', 'subject', 'category', 'variables']));

        // Re-extract variables if content changed
        if ($request->has('content') && !$request->has('variables')) {
            $template->update(['variables' => $template->extractVariables()]);
        }

        AuditLog::log($user->id, 'update', 'template', $template->id, $oldValues, $template->fresh()->toArray());

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $template->id,
                'name' => $template->name,
                'channel' => $template->channel,
                'content' => $template->content,
                'subject' => $template->subject,
                'variables' => $template->variables,
                'category' => $template->category,
            ],
            'message' => 'Template updated successfully',
        ]);
    }

    /**
     * Delete a template
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $template = Template::forUser($user->id)->find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'error' => 'Template not found',
            ], 404);
        }

        // Check if template is in use
        $usageCount = $template->campaigns()->count();
        if ($usageCount > 0) {
            return response()->json([
                'success' => false,
                'error' => "Cannot delete template. It is used by {$usageCount} campaign(s).",
            ], 422);
        }

        AuditLog::log($user->id, 'delete', 'template', $template->id, $template->toArray(), []);

        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Template deleted successfully',
        ]);
    }

    /**
     * Duplicate a template
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $template = Template::forUser($user->id)->find($id);

        if (!$template) {
            return response()->json([
                'success' => false,
                'error' => 'Template not found',
            ], 404);
        }

        $newTemplate = $template->replicate();
        $newTemplate->name = $template->name . ' (Copy)';
        $newTemplate->is_default = false;
        $newTemplate->save();

        AuditLog::log($user->id, 'duplicate', 'template', $newTemplate->id, [], $newTemplate->toArray());

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $newTemplate->id,
                'name' => $newTemplate->name,
            ],
            'message' => 'Template duplicated successfully',
        ], 201);
    }
}

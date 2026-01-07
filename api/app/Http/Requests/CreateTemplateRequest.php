<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'in:sms,email'],
            'content' => ['required', 'string', 'max:10000'],
            'subject' => ['required_if:channel,email', 'nullable', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:100'],
            'variables' => ['sometimes', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter a template name.',
            'channel.required' => 'Please select a channel (SMS or Email).',
            'channel.in' => 'Channel must be either sms or email.',
            'content.required' => 'Please enter template content.',
            'subject.required_if' => 'Email templates require a subject line.',
        ];
    }
}

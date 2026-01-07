<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateEmailCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:100000'],
            'from_email' => ['sometimes', 'email', 'max:255'],
            'from_name' => ['sometimes', 'string', 'max:255'],
            'recipients' => ['required_without:group_ids', 'array'],
            'recipients.*' => ['email'],
            'group_ids' => ['required_without:recipients', 'array'],
            'group_ids.*' => ['integer', 'exists:contact_groups,id'],
            'schedule_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter a campaign name.',
            'subject.required' => 'Please enter an email subject.',
            'message.required' => 'Please enter your email content.',
            'from_email.email' => 'Please enter a valid sender email address.',
            'recipients.required_without' => 'Please add recipients or select contact groups.',
            'recipients.*.email' => 'All recipients must be valid email addresses.',
            'schedule_at.after' => 'Scheduled time must be in the future.',
        ];
    }
}

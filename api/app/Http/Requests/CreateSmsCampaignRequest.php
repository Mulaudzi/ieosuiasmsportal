<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSmsCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:918'],
            'sender_id' => ['sometimes', 'string', 'max:11'],
            'recipients' => ['required_without:group_ids', 'array'],
            'recipients.*' => ['string'],
            'group_ids' => ['required_without:recipients', 'array'],
            'group_ids.*' => ['integer', 'exists:contact_groups,id'],
            'schedule_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter a campaign name.',
            'message.required' => 'Please enter your SMS message.',
            'message.max' => 'SMS message cannot exceed 918 characters (6 parts).',
            'sender_id.max' => 'Sender ID cannot exceed 11 characters.',
            'recipients.required_without' => 'Please add recipients or select contact groups.',
            'group_ids.required_without' => 'Please add recipients or select contact groups.',
            'schedule_at.after' => 'Scheduled time must be in the future.',
        ];
    }
}

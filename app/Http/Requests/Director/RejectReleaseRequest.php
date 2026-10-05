<?php

declare(strict_types=1);

namespace App\Http\Requests\Director;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D-113 — the Director rejects a college's request to release a batch's
 * documents. A written message is required: the College Admin reads it on the
 * batch's results page and acts on it before asking again. Same bounds as a
 * batch rejection (RejectBatchRequest, D-36).
 */
class RejectReleaseRequest extends FormRequest
{
    /** Bounds for the written message — also used by the popup's counter. */
    public const MESSAGE_MIN = 10;

    public const MESSAGE_MAX = 500;

    public function authorize(): bool
    {
        // Route middleware ('role:director') already gates access.
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'rejection_message' => ['required', 'string', 'min:'.self::MESSAGE_MIN, 'max:'.self::MESSAGE_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rejection_message.required' => 'Give the college a message saying why the documents are not released.',
            'rejection_message.min' => 'The message must be at least :min characters — the college admin has to act on it.',
            'rejection_message.max' => 'Please keep the message to :max characters.',
        ];
    }
}

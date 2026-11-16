<?php

namespace App\Modules\Moderation\Http\Requests;

use App\Modules\Moderation\Actions\ReferNotice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /admin/moderation/notices/{uuid}/referral : autorité informée (liste fermée).
 */
class ReferralRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'authority' => ['required', 'string', Rule::in(ReferNotice::AUTHORITIES)],
        ];
    }
}

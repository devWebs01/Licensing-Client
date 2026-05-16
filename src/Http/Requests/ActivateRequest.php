<?php

declare(strict_types=1);

namespace DevWebs01\LicensingClient\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ActivateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'license_key' => ['required', 'string'],
        ];
    }
}

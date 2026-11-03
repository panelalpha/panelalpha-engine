<?php

namespace App\Http\Requests;

use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OverlaySetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'html' => ['required', 'string', 'max:'.Overlay::MAX_HTML_BYTES, 'not_regex:/\x00/'],
            'position' => ['nullable', Rule::in(OverlayPosition::values())],
        ];
    }

    public function overlay(): Overlay
    {
        return new Overlay(
            (string) $this->validated('html'),
            OverlayPosition::tryFrom((string) $this->validated('position')) ?? OverlayPosition::BodyEnd,
        );
    }
}

<?php

namespace App\Http\Requests;

use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectOverlaySetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'html' => ['nullable', 'string', 'max:'.Overlay::MAX_HTML_BYTES, 'not_regex:/\x00/'],
            'position' => ['nullable', Rule::in(OverlayPosition::values())],
            'show_global' => ['sometimes', 'boolean'],
        ];
    }

    public function overlay(): ?Overlay
    {
        $html = $this->validated('html');
        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        return new Overlay(
            $html,
            OverlayPosition::tryFrom((string) $this->validated('position')) ?? OverlayPosition::BodyEnd,
        );
    }

    public function showGlobal(): bool
    {
        return $this->boolean('show_global', true);
    }
}

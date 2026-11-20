<?php

namespace App\Http\Requests;

use App\Enums\Quality;
use App\Enums\Size;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(Size::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['required', 'url', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'prompt.required' => 'Please provide a prompt describing the image to generate.',
            'prompt.max' => 'Prompts cannot exceed 4,000 characters.',
            'reference_images.max' => 'Pixelforge accepts at most 4 reference images.',
            'reference_images.*.url' => 'Each reference image must be a valid public URL.',
        ];
    }
}

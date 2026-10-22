<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Locale;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class CreateGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:'.User::NAME_MIN_LENGTH,
                'max:'.User::NAME_MAX_LENGTH,
                // Letters of any alphabet (Polish, French, German and Portuguese names), digits, underscore and dash.
                'regex:/^[\pL\pN_-]+$/u',
                $this->nameIsFree(...),
            ],
            'locale' => ['nullable', 'string', fn (string $attribute, mixed $value, Closure $fail) => Locale::normalize((string) $value) === null
                ? $fail('validation.in')->translate()
                : null],
        ];
    }

    /** Field names used in validation messages, in the language of the request. */
    public function attributes(): array
    {
        return ['name' => __('account.name.attribute')];
    }

    /** The preferred language: the one in the body, otherwise the language negotiated for this request. */
    public function preferredLocale(): string
    {
        return Locale::normalize($this->input('locale'))
            ?? Locale::normalize(str_replace('_', '-', app()->getLocale()))
            ?? Locale::default();
    }

    private function nameIsFree(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && User::query()->where('name_key', User::nameKey($value))->exists()) {
            $fail('account.name.taken')->translate();
        }
    }
}

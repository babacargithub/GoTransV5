<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PhoneNumber implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param Closure(string, ?string=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // the value should be of nine digits, it starts with (77, 78, 76, 70,75)
        if (!preg_match('/^(77|78|76|70|75)[0-9]{7}$/', $value)) {
            $fail("Le numéro téléphone $value n'est pas valide.");
        }
    }
}

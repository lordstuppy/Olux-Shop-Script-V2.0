<?php

namespace App\Enums\Concerns;

/**
 * Human-readable, translatable enum labels. The English text is the
 * translation key; scripts/extract-strings.php collects every case's
 * labelKey() into lang/en.json.
 */
trait HasLabel
{
    public function label(): string
    {
        return __($this->labelKey());
    }

    /** English label, used as the translation key. */
    public function labelKey(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}

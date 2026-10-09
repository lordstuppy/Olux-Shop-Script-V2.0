<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Support\TranslationCatalog;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class TranslationCatalogTest extends TestCase
{
    public function test_lang_en_json_lists_every_key_used_in_the_source(): void
    {
        $this->assertFileExists(TranslationCatalog::path());
        $this->assertSame(
            TranslationCatalog::encode(TranslationCatalog::extract()),
            file_get_contents(TranslationCatalog::path()),
            'lang/en.json is out of date. Run: php artisan shop:lang-extract',
        );
    }

    public function test_english_catalog_maps_each_key_to_itself(): void
    {
        foreach (json_decode(file_get_contents(TranslationCatalog::path()), true) as $key => $value) {
            $this->assertSame($key, $value);
        }
    }

    public function test_a_translation_file_changes_the_rendered_text(): void
    {
        $this->app['translator']->addLines(['*.Awaiting payment' => 'En attente de paiement'], 'fr');
        App::setLocale('fr');

        $this->assertSame('En attente de paiement', OrderStatus::Pending->label());
    }
}

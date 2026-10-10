<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use Tests\TestCase;

class StorageFailureTest extends TestCase
{
    public function test_an_upload_on_a_full_disk_gives_a_clear_error_and_stores_nothing(): void
    {
        $seller = User::factory()->seller()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->andThrow(UnableToWriteFile::atLocation('x', 'No space left on device'));
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::set('products', $disk);

        $this->actingAs($seller)->postForm(route('seller.products.files.store', $product->id), [
            'file' => UploadedFile::fake()->createWithContent('tool.zip', 'PK'.str_repeat('x', 2000)),
        ])->assertSessionHas('error', 'The file could not be saved because of a storage problem on our side. Nothing was stored; please try again later. Our team has been alerted.');

        $this->assertSame(0, $product->files()->count());
    }
}

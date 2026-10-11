<?php

namespace App\Jobs;

use App\Enums\ProductStatus;
use App\Mail\ProductDecisionMail;
use App\Models\ProductFile;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\Security\VirusScanner;
use App\Services\StaffAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Scans a seller upload. Infected files are deleted from disk, retired and
 * their product is disabled. Scanner outages are retried with backoff.
 */
class ScanProductFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $fileId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(VirusScanner $scanner, AuditLogger $audit): void
    {
        $file = ProductFile::query()->with('product')->find($this->fileId);
        if ($file === null || ! in_array($file->scan_status, ['pending', 'error'], true)) {
            return;
        }

        if (config('shop.virus_scan') === 'disabled') {
            $file->forceFill(['scan_status' => 'skipped', 'scan_detail' => 'scanning disabled', 'scanned_at' => now()])->save();

            return;
        }

        $result = $scanner->scan(Storage::disk('products')->path($file->storage_path));
        $file->forceFill(['scan_status' => $result['status'], 'scan_detail' => $result['detail'], 'scanned_at' => now()])->save();

        if ($result['status'] === 'infected') {
            Storage::disk('products')->delete($file->storage_path);
            $file->forceFill(['retired_at' => now()])->save();
            $product = $file->product;
            $product->status = ProductStatus::Disabled;
            $product->moderation_note = __('An uploaded file failed the virus scan and was removed. Upload a clean file, then submit the product for review again.');
            $product->save();
            Log::warning('Infected upload {file_id} ({signature}) removed; product {product_id} disabled', [
                'file_id' => $file->id, 'signature' => $result['detail'], 'product_id' => $product->id,
            ]);
            $audit->log('product.file_infected', $product, ['file_id' => $file->id, 'signature' => $result['detail']]);
            if ($product->seller !== null) {
                app(Notifier::class)->send($product->seller, new ProductDecisionMail($product, false, (string) $product->moderation_note));
            }
            app(StaffAlerts::class)->send(__('Infected upload removed from ":title"', ['title' => $product->title]), [
                __('Signature: :signature', ['signature' => (string) $result['detail']]),
                __('The file was deleted and the product disabled. Repeated uploads like this may mean the seller account is compromised.'),
            ], route('admin.products.show', $product));

            return;
        }

        if ($result['status'] === 'error') {
            // Let the queue retry; the file stays undeliverable meanwhile.
            throw new RuntimeException("Virus scan of product file {$file->id} failed: {$result['detail']}");
        }
    }
}

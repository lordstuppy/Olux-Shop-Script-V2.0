<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * PDF invoices stored on the "invoices" disk (storage/invoices/).
 */
class InvoiceService
{
    /** Re-renders the PDF (same number), for example after refunds. */
    public function regenerate(Order $order): Invoice
    {
        $invoice = Invoice::query()->where('order_id', $order->id)->first();
        if ($invoice !== null && $invoice->storage_path !== null) {
            Storage::disk('invoices')->delete($invoice->storage_path);
            $invoice->forceFill(['storage_path' => null])->save();
        }

        return $this->issue($order);
    }

    public function issue(Order $order): Invoice
    {
        $invoice = DB::transaction(function () use ($order) {
            $existing = Invoice::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            return Invoice::create([
                'order_id' => $order->id,
                // Order ids are unique, so the number is too; the year aids filing.
                'number' => sprintf('INV-%s-%06d', ($order->paid_at ?? now())->format('Y'), $order->id),
                'issued_at' => $order->paid_at ?? now(),
            ]);
        });

        if ($invoice->storage_path === null || ! Storage::disk('invoices')->exists($invoice->storage_path)) {
            $path = $invoice->number.'.pdf';
            $pdf = Pdf::loadView('invoices.pdf', [
                'invoice' => $invoice,
                'order' => $order->load(['items', 'buyer', 'refunds']),
            ]);
            Storage::disk('invoices')->put($path, $pdf->output());
            $invoice->storage_path = $path;
            $invoice->save();
            Log::info('Invoice {number} stored for order {public_id}', ['number' => $invoice->number, 'public_id' => $order->public_id]);
        }

        return $invoice;
    }
}

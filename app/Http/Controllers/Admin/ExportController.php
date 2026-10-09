<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV exports for accounting. Streamed in chunks; cells that a spreadsheet
 * would treat as a formula are prefixed with a quote (CSV injection).
 */
class ExportController extends Controller
{
    public const TYPES = ['orders', 'payments', 'payouts', 'ledger', 'balances'];

    public function index(): View
    {
        return view('admin.exports', ['types' => [
            'orders' => __('Orders'),
            'payments' => __('Payments and refunds'),
            'payouts' => __('Seller payouts'),
            'ledger' => __('Seller ledger entries'),
            'balances' => __('Buyer balance transactions'),
        ]]);
    }

    public function download(Request $request, AuditLogger $audit): StreamedResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(self::TYPES)],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        [$columns, $query, $map] = $this->definition($data['type'], $from, $to);

        $audit->log('export.downloaded', null, ['type' => $data['type'], 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
        $filename = "{$data['type']}-{$from->toDateString()}-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($columns, $query, $map) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            $query->orderBy('id')->chunk(1000, function ($rows) use ($out, $map) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map([self::class, 'cell'], $map($row)));
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralises spreadsheet formulas in exported text. */
    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value) ? "'".$value : $value;
    }

    private function definition(string $type, Carbon $from, Carbon $to): array
    {
        $amount = fn (?int $minor, ?string $currency) => $minor === null || $currency === null ? '' : Money::toDecimal($minor, $currency);

        return match ($type) {
            'orders' => [
                ['id', 'public_id', 'created_at', 'paid_at', 'status', 'buyer_email', 'currency', 'subtotal', 'discount', 'total', 'refunded'],
                DB::table('orders')->join('users', 'users.id', '=', 'orders.buyer_id')->whereBetween('orders.created_at', [$from, $to])
                    ->select('orders.*', 'users.email as buyer_email'),
                fn ($r) => [$r->id, $r->public_id, $r->created_at, $r->paid_at, $r->status, $r->buyer_email, $r->currency,
                    $amount((int) $r->subtotal_minor, $r->currency), $amount((int) $r->discount_minor, $r->currency), $amount((int) $r->total_minor, $r->currency), $amount((int) $r->refunded_minor, $r->currency)],
            ],
            'payments' => [
                ['id', 'order_public_id', 'kind', 'provider', 'provider_reference', 'status', 'currency', 'amount', 'received', 'crypto', 'crypto_amount', 'created_at', 'confirmed_at'],
                DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')->whereBetween('payments.created_at', [$from, $to])
                    ->select('payments.*', 'orders.public_id as order_public_id'),
                fn ($r) => [$r->id, $r->order_public_id, $r->kind, $r->provider, $r->provider_reference, $r->status, $r->currency,
                    $amount((int) $r->amount_minor, $r->currency), $amount((int) $r->received_minor, $r->currency), $r->crypto, $r->crypto_amount, $r->created_at, $r->confirmed_at],
            ],
            'payouts' => [
                ['id', 'seller_email', 'status', 'provider', 'currency', 'amount', 'crypto', 'crypto_amount', 'destination', 'reference', 'created_at', 'processed_at'],
                DB::table('payouts')->join('users', 'users.id', '=', 'payouts.seller_id')->whereBetween('payouts.created_at', [$from, $to])
                    ->select('payouts.*', 'users.email as seller_email'),
                fn ($r) => [$r->id, $r->seller_email, $r->status, $r->provider, $r->currency, $amount((int) $r->amount_minor, $r->currency),
                    $r->crypto, $r->crypto_amount, $r->destination, $r->reference, $r->created_at, $r->processed_at],
            ],
            'ledger' => [
                ['id', 'seller_email', 'type', 'currency', 'amount', 'order_item_id', 'payout_id', 'created_at'],
                DB::table('seller_ledger_entries')->join('users', 'users.id', '=', 'seller_ledger_entries.seller_id')->whereBetween('seller_ledger_entries.created_at', [$from, $to])
                    ->select('seller_ledger_entries.*', 'users.email as seller_email'),
                fn ($r) => [$r->id, $r->seller_email, $r->type, $r->currency, $amount((int) $r->amount_minor, $r->currency), $r->order_item_id, $r->payout_id, $r->created_at],
            ],
            'balances' => [
                ['id', 'user_email', 'type', 'currency', 'amount', 'balance_after', 'note', 'created_at'],
                DB::table('balance_transactions')->join('users', 'users.id', '=', 'balance_transactions.user_id')->whereBetween('balance_transactions.created_at', [$from, $to])
                    ->select('balance_transactions.*', 'users.email as user_email'),
                fn ($r) => [$r->id, $r->user_email, $r->type, $r->currency, $amount((int) $r->amount_minor, $r->currency), $amount((int) $r->balance_after_minor, $r->currency), $r->note, $r->created_at],
            ],
        };
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\UserStatus;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProductModerationService;
use App\Services\UserService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk actions from the admin lists. Each acts on the ticked rows, or on
 * every row matching the list's current filter ("scope=filtered"). Rows
 * that fail a check are skipped and reported; the rest go through the same
 * service as the single action, so the same rules and audit entries apply.
 */
class BulkController extends Controller
{
    private const MAX_ROWS = 500;

    public function products(Request $request, ProductModerationService $moderation, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_map(fn ($s) => $s->value, ProductModerationService::STAFF_STATUSES))],
            'scope' => ['required', Rule::in(['selected', 'filtered'])],
            'ids' => ['array', 'max:'.self::MAX_ROWS],
            'ids.*' => ['integer'],
            'filter_status' => ['nullable', Rule::enum(ProductStatus::class)],
        ]);
        $query = Product::query()->orderBy('id');
        $data['scope'] === 'selected'
            ? $query->whereIn('id', $data['ids'] ?? [])
            : $query->when($data['filter_status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s));
        $status = ProductStatus::from($data['action']);

        [$done, $skipped] = $this->each($query, fn (Product $p) => $moderation->setStatus($p, $status, $request->user()));
        $audit->log('bulk.product_status', null, ['to' => $status->value, 'changed' => $done, 'skipped' => count($skipped)]);

        return $this->report($done, $skipped, __('products set to :status', ['status' => mb_strtolower($status->label())]));
    }

    public function users(Request $request, UserService $users, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in([UserStatus::Suspended->value, UserStatus::Active->value])],
            'scope' => ['required', Rule::in(['selected', 'filtered'])],
            'ids' => ['array', 'max:'.self::MAX_ROWS],
            'ids.*' => ['integer'],
            'filter_q' => ['nullable', 'string', 'max:100'],
            'filter_role' => ['nullable', 'string', 'max:32'],
        ]);
        $query = User::query()->orderBy('id');
        if ($data['scope'] === 'selected') {
            $query->whereIn('id', $data['ids'] ?? []);
        } else {
            if (! empty($data['filter_q'])) {
                $like = '%'.addcslashes($data['filter_q'], '%_\\').'%';
                $query->where(fn ($q) => $q->where('email', 'ILIKE', $like)->orWhere('name', 'ILIKE', $like));
            }
            if (! empty($data['filter_role'])) {
                $query->where('role', $data['filter_role']);
            }
        }
        $status = UserStatus::from($data['action']);

        [$done, $skipped] = $this->each($query, function (User $user) use ($users, $status, $request) {
            if ($user->status === $status) {
                return;
            }
            $users->setStatus($user, $status, $request->user());
        });
        $audit->log('bulk.user_status', null, ['to' => $status->value, 'changed' => $done, 'skipped' => count($skipped)]);

        return $this->report($done, $skipped, __('accounts set to :status', ['status' => mb_strtolower($status->label())]));
    }

    public function exportOrders(Request $request, AuditLogger $audit): StreamedResponse|RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['selected', 'filtered'])],
            'ids' => ['array', 'max:'.self::MAX_ROWS],
            'ids.*' => ['string', 'max:36'],
            'filter_status' => ['nullable', Rule::enum(OrderStatus::class)],
            'filter_q' => ['nullable', 'string', 'max:64'],
            'filter_buyer' => ['nullable', 'string', 'max:255'],
            'filter_seller' => ['nullable', 'integer', 'min:1'],
            'filter_from' => ['nullable', 'date_format:Y-m-d'],
            'filter_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = DB::table('orders')->join('users', 'users.id', '=', 'orders.buyer_id')
            ->select('orders.*', 'users.email as buyer_email')
            ->selectSub(fn ($q) => $q->from('order_items')->whereColumn('order_items.order_id', 'orders.id')->selectRaw('COALESCE(SUM(quantity), 0)'), 'units');
        if ($data['scope'] === 'selected') {
            if (($data['ids'] ?? []) === []) {
                return back()->with('error', __('Tick at least one order, or choose "all orders matching the filter".'));
            }
            $query->whereIn(DB::raw('CAST(orders.public_id AS TEXT)'), $data['ids']);
        } else {
            $filters = [];
            foreach (['status', 'q', 'buyer', 'seller', 'from', 'to'] as $key) {
                $filters[$key] = $data['filter_'.$key] ?? null;
            }
            $query->whereIn('orders.id', OrderController::filtered($filters)->select('id'));
        }
        $audit->log('bulk.orders_exported', null, ['scope' => $data['scope'], 'count' => (clone $query)->count()]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['public_id', 'created_at', 'paid_at', 'status', 'buyer_email', 'currency', 'units', 'subtotal', 'discount', 'total', 'refunded']);
            $query->orderBy('orders.id')->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    fputcsv($out, array_map([ExportController::class, 'cell'], [
                        $r->public_id, $r->created_at, $r->paid_at, $r->status, $r->buyer_email, $r->currency, $r->units,
                        Money::toDecimal((int) $r->subtotal_minor, $r->currency), Money::toDecimal((int) $r->discount_minor, $r->currency),
                        Money::toDecimal((int) $r->total_minor, $r->currency), Money::toDecimal((int) $r->refunded_minor, $r->currency),
                    ]));
                }
            });
            fclose($out);
        }, 'orders-selection-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: int, 1: list<string>} rows changed and reasons for skipped rows */
    private function each(Builder $query, callable $action): array
    {
        $rows = $query->limit(self::MAX_ROWS + 1)->get();
        if ($rows->isEmpty()) {
            throw new UserFacingException(__('Nothing to change: tick at least one row, or choose "all rows matching the filter".'));
        }
        if ($rows->count() > self::MAX_ROWS) {
            throw new UserFacingException(__('A bulk action can change at most :max rows at once. Narrow the filter and try again.', ['max' => self::MAX_ROWS]));
        }
        $done = 0;
        $skipped = [];
        foreach ($rows as $row) {
            try {
                DB::transaction(fn () => $action($row));
                $done++;
            } catch (UserFacingException $e) {
                $skipped[] = $e->getMessage();
            }
        }

        return [$done, $skipped];
    }

    /** @param list<string> $skipped */
    private function report(int $done, array $skipped, string $what): RedirectResponse
    {
        $response = back()->with('success', __('Bulk action finished: :done :what, :skipped skipped.', ['done' => $done, 'what' => $what, 'skipped' => count($skipped)]));

        return $skipped === [] ? $response : $response->with('bulk_skipped', array_slice($skipped, 0, 50));
    }
}

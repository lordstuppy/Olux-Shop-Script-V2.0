<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CouponType;
use App\Exceptions\UserFacingException;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\AuditLogger;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class CouponController extends Controller
{
    public function index(): View
    {
        return view('admin.coupons', ['coupons' => Coupon::latest('id')->paginate(30), 'currencies' => Money::supported()]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'type' => ['required', Rule::enum(CouponType::class)],
            'value' => ['required', 'string', 'max:16'],
            'currency' => ['nullable', 'required_if:type,fixed', Rule::in(Money::supported())],
            'min_total' => ['nullable', 'string', 'max:16'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'max_per_user' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $code = Coupon::normalizeCode($data['code']);
        if (Coupon::where('code', $code)->exists()) {
            throw new UserFacingException("Coupon {$code} already exists.");
        }

        $type = CouponType::from($data['type']);
        $currency = $data['currency'] ?? null;
        try {
            // Percent coupons take a percentage such as 15 or 12.5, stored as basis points.
            $value = $type === CouponType::Percent
                ? BigDecimal::of($this->decimal($data['value']))->multipliedBy(100)->toScale(0)->toInt()
                : Money::parseInput($data['value'], (string) $currency);
            $minTotal = ! empty($data['min_total']) ? Money::parseInput($data['min_total'], $currency ?? (string) config('shop.default_currency')) : 0;
        } catch (InvalidArgumentException) {
            throw new UserFacingException('Enter the value and minimum total as plain numbers, for example 10 or 12.50.');
        }
        if ($value <= 0 || ($type === CouponType::Percent && $value > 10000)) {
            throw new UserFacingException('A percent coupon must be between 0.01 and 100 percent; a fixed coupon must be positive.');
        }

        $coupon = Coupon::create([
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'currency' => $currency,
            'min_total_minor' => $minTotal,
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'max_per_user' => $data['max_per_user'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'active' => true,
            'created_by' => $request->user()->id,
        ]);
        $audit->log('coupon.created', $coupon, ['type' => $type->value, 'value' => $value]);

        return back()->with('success', "Coupon {$code} created.");
    }

    public function toggle(Coupon $coupon, AuditLogger $audit): RedirectResponse
    {
        $coupon->active = ! $coupon->active;
        $coupon->save();
        $audit->log($coupon->active ? 'coupon.activated' : 'coupon.deactivated', $coupon);

        return back()->with('success', "Coupon {$coupon->code} is now ".($coupon->active ? 'active' : 'inactive').'.');
    }

    private function decimal(string $value): string
    {
        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', trim($value))) {
            throw new InvalidArgumentException('not a percentage');
        }

        return trim($value);
    }
}

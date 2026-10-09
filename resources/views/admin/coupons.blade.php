@extends('layouts.app')

@section('title', 'Coupons - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Coupons</h1>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Code</th><th scope="col">Discount</th><th scope="col">Minimum</th><th scope="col">Used</th><th scope="col">Expires</th><th scope="col">Active</th><th scope="col">Action</th></tr></thead>
            <tbody>
                @forelse ($coupons as $coupon)
                    <tr>
                        <td class="mono">{{ $coupon->code }}</td>
                        <td>{{ $coupon->type === \App\Enums\CouponType::Percent ? \App\Support\Money::toDecimal($coupon->value, 'USD').'%' : money($coupon->value, $coupon->currency) }}{{ $coupon->type === \App\Enums\CouponType::Percent && $coupon->currency ? ' ('.$coupon->currency.' only)' : '' }}</td>
                        <td>{{ $coupon->min_total_minor > 0 ? \App\Support\Money::toDecimal($coupon->min_total_minor, $coupon->currency ?? config('shop.default_currency')) : '-' }}</td>
                        <td>{{ $coupon->redemptions_count }}{{ $coupon->max_redemptions ? ' / '.$coupon->max_redemptions : '' }}</td>
                        <td>{{ $coupon->expires_at?->format('Y-m-d') ?? 'Never' }}</td>
                        <td>{{ $coupon->active ? 'Yes' : 'No' }}</td>
                        <td>
                            <form method="post" action="{{ route('admin.coupons.toggle', $coupon) }}">
                                @csrf
                                <button type="submit" class="btn-secondary">{{ $coupon->active ? 'Deactivate' : 'Activate' }}<span class="visually-hidden"> {{ $coupon->code }}</span></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7">No coupons.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $coupons->links() }}

    <h2>Create coupon</h2>
    <form method="post" action="{{ route('admin.coupons.store') }}" class="stack">
        @csrf
        <x-field name="code" label="Code" maxlength="40" hint="Letters, digits, dash and underscore. Stored in upper case." required />
        <x-select name="type" label="Type" :options="['percent' => 'Percent off', 'fixed' => 'Fixed amount off']" />
        <x-field name="value" label="Value" inputmode="decimal" hint="Percent (e.g. 15 or 12.5) or amount (e.g. 5.00)." required />
        <x-select name="currency" label="Currency" :options="array_combine($currencies, $currencies)" placeholder="Any currency (percent only)" hint="Required for fixed coupons. Restricts the coupon to orders in this currency." />
        <x-field name="min_total" label="Minimum subtotal" inputmode="decimal" hint="Optional, in the coupon currency." />
        <x-field name="max_redemptions" label="Maximum redemptions" type="number" min="1" />
        <x-field name="expires_at" label="Expires at" type="datetime-local" />
        <button type="submit">Create coupon</button>
    </form>
@endsection

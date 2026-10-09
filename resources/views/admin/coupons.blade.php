@extends('layouts.app')

@section('title', __('Coupons - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Coupons') }}</h1>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Code') }}</th><th scope="col">{{ __('Discount') }}</th><th scope="col">{{ __('Minimum') }}</th><th scope="col">{{ __('Used') }}</th><th scope="col">{{ __('Per buyer') }}</th><th scope="col">{{ __('Expires') }}</th><th scope="col">{{ __('Active') }}</th><th scope="col">{{ __('Action') }}</th></tr></thead>
            <tbody>
                @forelse ($coupons as $coupon)
                    <tr>
                        <td class="mono">{{ $coupon->code }}</td>
                        <td>{{ $coupon->type === \App\Enums\CouponType::Percent ? \App\Support\Money::toDecimal($coupon->value, 'USD').'%' : money($coupon->value, $coupon->currency) }}{{ $coupon->type === \App\Enums\CouponType::Percent && $coupon->currency ? ' '.__('(:currency only)', ['currency' => $coupon->currency]) : '' }}</td>
                        <td>{{ $coupon->min_total_minor > 0 ? \App\Support\Money::toDecimal($coupon->min_total_minor, $coupon->currency ?? config('shop.default_currency')) : '-' }}</td>
                        <td>{{ $coupon->redemptions_count }}{{ $coupon->max_redemptions ? ' / '.$coupon->max_redemptions : '' }}</td>
                        <td>{{ $coupon->max_per_user ?? __('Unlimited') }}</td>
                        <td>{{ $coupon->expires_at?->format('Y-m-d') ?? __('Never') }}</td>
                        <td>{{ $coupon->active ? __('Yes') : __('No') }}</td>
                        <td>
                            <form method="post" action="{{ route('admin.coupons.toggle', $coupon) }}">
                                @csrf
                                <button type="submit" class="btn-secondary">{{ $coupon->active ? __('Deactivate') : __('Activate') }}<span class="visually-hidden"> {{ $coupon->code }}</span></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">{{ __('No coupons.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $coupons->links() }}

    <h2>{{ __('Create coupon') }}</h2>
    <form method="post" action="{{ route('admin.coupons.store') }}" class="stack">
        @csrf
        <x-field name="code" :label="__('Code')" maxlength="40" :hint="__('Letters, digits, dash and underscore. Stored in upper case.')" required />
        <x-select name="type" :label="__('Type')" :options="['percent' => __('Percent off'), 'fixed' => __('Fixed amount off')]" />
        <x-field name="value" :label="__('Value')" inputmode="decimal" :hint="__('Percent (e.g. 15 or 12.5) or amount (e.g. 5.00).')" required />
        <x-select name="currency" :label="__('Currency')" :options="array_combine($currencies, $currencies)" :placeholder="__('Any currency (percent only)')" :hint="__('Required for fixed coupons. Restricts the coupon to orders in this currency.')" />
        <x-field name="min_total" :label="__('Minimum subtotal')" inputmode="decimal" :hint="__('Optional, in the coupon currency.')" />
        <x-field name="max_redemptions" :label="__('Maximum redemptions')" type="number" min="1" />
        <x-field name="max_per_user" :label="__('Maximum uses per buyer')" type="number" min="1" />
        <x-field name="expires_at" :label="__('Expires at')" type="datetime-local" />
        <button type="submit">{{ __('Create coupon') }}</button>
    </form>
@endsection

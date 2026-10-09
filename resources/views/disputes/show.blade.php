@extends('layouts.app')

@section('title', __('Dispute #:id', ['id' => $dispute->id]))
@section('noindex', true)

@section('content')
    <h1>{{ __('Dispute #:id: :title', ['id' => $dispute->id, 'title' => $item->title]) }}</h1>
    <p>
        {{ __('Status:') }} <x-status :value="$dispute->status" />
        &middot; {{ __('Reason:') }} {{ $dispute->reason->label() }}
        &middot; {{ __('Buyer asks for:') }} {{ $dispute->requested_outcome === 'refund' ? __('a refund') : __('a replacement') }}
        &middot; {{ __('Order') }} <a class="mono" href="{{ $staff ? route('admin.orders.show', $dispute->order) : ($role === 'buyer' ? route('orders.show', $dispute->order) : route('seller.sales')) }}">{{ $dispute->order->shortId() }}</a>
        &middot; {{ money($item->netMinor(), $dispute->order->currency) }}
    </p>
    @if ($staff)
        <p>{{ __('Buyer') }} <a href="{{ route('admin.users.show', $dispute->buyer) }}">{{ $dispute->buyer->email }}</a>
            &middot; {{ __('Seller') }} <a href="{{ route('admin.users.show', $dispute->seller) }}">{{ $dispute->seller->sellerProfile?->display_name ?? $dispute->seller->email }}</a>
            &middot; {{ __('Delivered: :when', ['when' => $item->delivered_at ? $item->delivered_at->format('Y-m-d H:i').' UTC' : __('no')]) }}
            &middot; {{ __('Refundable: :amount', ['amount' => money($refundable, $dispute->order->currency)]) }}</p>
    @endif

    @if ($dispute->status === \App\Enums\DisputeStatus::AwaitingSeller)
        <p class="flash flash-info" role="status">
            @if ($role === 'seller')
                {{ __('Please respond by :time UTC. After that the case goes to our team without your answer. Your earnings for this item are held until the dispute is resolved.', ['time' => $dispute->seller_respond_by->format('Y-m-d H:i')]) }}
            @else
                {{ __('Waiting for the seller to respond until :time UTC.', ['time' => $dispute->seller_respond_by->format('Y-m-d H:i')]) }}
            @endif
        </p>
    @elseif ($dispute->status === \App\Enums\DisputeStatus::AwaitingStaff)
        <p class="flash flash-info" role="status">{{ $dispute->escalated_at ? __('The seller did not respond in time. Our team will decide.') : __('Our team is reviewing the case.') }}</p>
    @else
        <p class="flash flash-success" role="status">{{ __('Closed :time UTC: :resolution.', ['time' => $dispute->resolved_at?->format('Y-m-d H:i'), 'resolution' => $dispute->resolution?->label()]) }}
            @if ($dispute->refund_minor) {{ __('Refund: :amount.', ['amount' => money($dispute->refund_minor, $dispute->order->currency)]) }} @endif</p>
    @endif

    <h2>{{ __('Conversation') }}</h2>
    @foreach ($messages as $message)
        <article class="message {{ $message->internal ? 'message-internal' : ($message->author_role === 'staff' || $message->author_role === 'system' ? 'message-staff' : '') }}">
            <p class="meta">
                @if ($message->internal) <strong>{{ __('Internal note') }}</strong> @endif
                {{ match ($message->author_role) { 'buyer' => __('Buyer'), 'seller' => __('Seller'), 'staff' => __('Support team'), default => __('System') } }}
                @if ($staff && $message->author) ({{ $message->author->email }}) @endif
                &middot; {{ $message->created_at->format('Y-m-d H:i') }} UTC
            </p>
            <div class="description">{{ $message->body }}</div>
        </article>
    @endforeach

    @if ($dispute->isOpen())
        <h2>{{ $role === 'seller' && ! $dispute->seller_responded_at ? __('Respond to the buyer') : __('Add a message') }}</h2>
        <form method="post" action="{{ route('disputes.reply', $dispute) }}" class="stack">
            @csrf
            <x-textarea name="body" :label="__('Message')" maxlength="5000" required />
            @if ($staff)
                <label class="check"><input type="checkbox" name="internal" value="1"> {{ __('Internal note (only staff see it, no email)') }}</label>
            @endif
            <button type="submit">{{ __('Send') }}</button>
        </form>
        @if ($role === 'buyer')
            <form method="post" action="{{ route('disputes.withdraw', $dispute) }}" class="mt">
                @csrf
                <button type="submit" class="btn-secondary">{{ __('Withdraw dispute (problem solved)') }}</button>
            </form>
        @endif

        @if ($staff)
            <h2>{{ __('Resolve') }}</h2>
            <p class="hint">{{ __('The buyer and the seller are emailed the outcome and your note.') }}</p>
            <div class="two-col">
                <section class="card" aria-labelledby="resolve-refund">
                    <h3 id="resolve-refund">{{ __('Refund this item') }}</h3>
                    <form method="post" action="{{ route('admin.disputes.resolve', $dispute) }}" class="stack">
                        @csrf
                        <input type="hidden" name="action" value="refund">
                        <x-field name="amount" :id="'refund-amount'" :label="__('Amount (:currency)', ['currency' => $dispute->order->currency])" :value="\App\Support\Money::toDecimal($refundable, $dispute->order->currency)" inputmode="decimal" required />
                        <x-select name="method" :id="'refund-method'" :label="__('Method')" :options="array_merge(['balance' => __('Credit buyer balance'), 'manual' => __('Paid back outside the shop')], $cryptos !== [] ? ['shkeeper' => __('Send crypto via Shkeeper')] : [])" />
                        <x-field name="reference" :id="'refund-reference'" :label="__('Transaction reference (manual refunds)')" maxlength="128" />
                        @if ($cryptos !== [])
                            <x-select name="crypto" :id="'refund-crypto'" :label="__('Cryptocurrency (Shkeeper refunds)')" :options="collect($cryptos)->pluck('display_name', 'name')->all()" :placeholder="__('Choose')" />
                            <x-field name="destination" :id="'refund-destination'" :label="__('Buyer\'s address (Shkeeper refunds)')" maxlength="255" />
                        @endif
                        <x-textarea name="note" :id="'refund-note'" :label="__('Note to both parties')" maxlength="5000" />
                        <button type="submit">{{ __('Refund and close') }}</button>
                    </form>
                </section>
                <section class="card" aria-labelledby="resolve-replace">
                    <h3 id="resolve-replace">{{ __('Send a replacement') }}</h3>
                    <p class="hint">{{ __('Licence-key items get fresh keys from the product\'s stock; file items get fresh download links with the counter reset; manual items need the new delivery details below.') }}</p>
                    <form method="post" action="{{ route('admin.disputes.resolve', $dispute) }}" class="stack">
                        @csrf
                        <input type="hidden" name="action" value="replacement">
                        <x-textarea name="replacement_text" :id="'replacement-text'" :label="__('Replacement details shown to the buyer (stored encrypted)')" maxlength="10000" />
                        <x-textarea name="note" :id="'replacement-note'" :label="__('Note to both parties')" maxlength="5000" />
                        <button type="submit">{{ __('Deliver replacement and close') }}</button>
                    </form>
                    <h3 class="mt">{{ __('Reject') }}</h3>
                    <form method="post" action="{{ route('admin.disputes.resolve', $dispute) }}" class="stack">
                        @csrf
                        <input type="hidden" name="action" value="reject">
                        <x-textarea name="note" :id="'reject-note'" :label="__('Reason for the buyer and the seller')" maxlength="5000" required />
                        <button type="submit" class="btn-danger">{{ __('Reject and close') }}</button>
                    </form>
                </section>
            </div>
        @endif
    @endif
@endsection

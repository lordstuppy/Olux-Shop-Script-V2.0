@if ($profile->status === \App\Enums\SellerProfileStatus::Approved)
Your application to sell as "{{ $profile->display_name }}" was approved.

Add your first product: {{ route('seller.products.create') }}
Every product is reviewed before it is listed.
@else
Your application to sell as "{{ $profile->display_name }}" was not approved.

Reason: {{ $profile->review_note }}

You may update the details and apply again: {{ route('seller.apply') }}
@endif

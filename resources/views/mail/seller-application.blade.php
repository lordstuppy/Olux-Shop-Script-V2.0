@if ($profile->status === \App\Enums\SellerProfileStatus::Approved)
{!! __('Your application to sell as ":shop" was approved.', ['shop' => e($profile->display_name)]) !!}

{{ __('Add your first product:') }} {{ route('seller.products.create') }}
{{ __('Every product is reviewed before it is listed.') }}
@else
{!! __('Your application to sell as ":shop" was not approved.', ['shop' => e($profile->display_name)]) !!}

{{ __('Reason:') }} {{ $profile->review_note }}

{{ __('You may update the details and apply again:') }} {{ route('seller.apply') }}
@endif

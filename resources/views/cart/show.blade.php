@extends('layouts.app')

@section('title', 'Cart')
@section('noindex', true)

@section('content')
    <h1>Your cart</h1>

    @foreach ($totals['problems'] as $problem)
        <div class="flash flash-info" role="status">{{ $problem }}</div>
    @endforeach

    @if ($totals['lines'] === [])
        <p>Your cart is empty. <a href="{{ route('products.index') }}">Browse products</a>.</p>
    @else
        <div class="table-wrap">
            <table>
                <caption>Items, priced in {{ $totals['currency'] }}</caption>
                <thead>
                    <tr>
                        <th scope="col">Product</th>
                        <th scope="col" class="num">Unit price</th>
                        <th scope="col">Quantity</th>
                        <th scope="col" class="num">Line total</th>
                        <th scope="col"><span class="visually-hidden">Remove</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($totals['lines'] as $line)
                        @php $product = $line['product']; @endphp
                        <tr>
                            <td>
                                <a href="{{ route('products.show', $product->slug) }}">{{ $product->title }}</a>
                                @if ($product->currency !== $totals['currency'])
                                    <br><span class="muted">Listed at {{ money($product->price_minor, $product->currency) }}; rate {{ rtrim(rtrim($line['rate'], '0'), '.') }}</span>
                                @endif
                            </td>
                            <td class="num">{{ money($line['unit_minor'], $totals['currency']) }}</td>
                            <td>
                                <form method="post" action="{{ route('cart.update', $product->id) }}" class="actions">
                                    @csrf
                                    @method('PUT')
                                    <label class="visually-hidden" for="qty-{{ $product->id }}">Quantity of {{ $product->title }}</label>
                                    <input id="qty-{{ $product->id }}" type="number" name="quantity" value="{{ $line['quantity'] }}" min="0" max="{{ config('shop.max_quantity_per_line') }}" class="qty-input">
                                    <button type="submit" class="btn-secondary">Update</button>
                                </form>
                            </td>
                            <td class="num">{{ money($line['line_minor'], $totals['currency']) }}</td>
                            <td>
                                <form method="post" action="{{ route('cart.remove', $product->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-link">Remove<span class="visually-hidden"> {{ $product->title }}</span></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="row" colspan="3">Subtotal</th>
                        <td class="num"><strong>{{ money($totals['subtotal_minor'], $totals['currency']) }}</strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <form method="post" action="{{ route('cart.currency') }}" class="actions mt">
            @csrf
            <label for="cart-currency">Pay in</label>
            <select id="cart-currency" name="currency">
                @foreach ($currencies as $code)
                    <option value="{{ $code }}" @selected($code === $totals['currency'])>{{ $code }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-secondary">Change currency</button>
        </form>

        <p class="mt"><a class="btn" href="{{ route('checkout.show') }}">Continue to checkout</a></p>
    @endif
@endsection

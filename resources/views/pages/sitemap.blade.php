{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>{{ route('home') }}</loc></url>
    <url><loc>{{ route('products.index') }}</loc></url>
@foreach ($categories as $category)
    <url><loc>{{ route('products.index', ['category' => $category->slug]) }}</loc><lastmod>{{ $category->updated_at?->toAtomString() }}</lastmod></url>
@endforeach
@foreach ($products as $product)
    <url><loc>{{ route('products.show', $product->slug) }}</loc><lastmod>{{ $product->updated_at?->toAtomString() }}</lastmod></url>
@endforeach
    <url><loc>{{ route('pages.terms') }}</loc></url>
    <url><loc>{{ route('pages.privacy') }}</loc></url>
    <url><loc>{{ route('pages.retention') }}</loc></url>
</urlset>

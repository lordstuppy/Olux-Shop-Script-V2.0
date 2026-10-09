{{--
    Server-rendered SVG column chart (no JavaScript). One series, one axis.
    Columns <= 24px with a 4px rounded top, square at the baseline; hairline
    gridlines; native <title> tooltips; only the maximum is labelled. The
    data table below the chart carries every value.
    Params: $series (list of ['date' => 'Y-m-d', 'minor' => int]), $currency, $caption,
    optional $kind ('money' or 'count'; counts use 'minor' for the value) and $chartId.
--}}
@php
    $kind ??= 'money';
    $chartId ??= 'chart-'.$currency;
    $fmt = fn (int $v) => $kind === 'count' ? number_format($v) : money($v, $currency);
    $w = 720; $h = 240; $left = 64; $right = 12; $top = 24; $bottom = 32;
    $plotW = $w - $left - $right; $plotH = $h - $top - $bottom;
    $max = max(1, max(array_column($series, 'minor')));
    $unit = $kind === 'count' ? 1 : 10 ** \App\Support\Money::exponent($currency);
    // Round the axis to a clean step: 1, 2 or 5 x 10^n major units.
    $rawStep = $max / $unit / 4;
    $magnitude = 10 ** floor(log10(max($rawStep, 0.01)));
    $step = collect([1, 2, 5, 10])->map(fn ($m) => $m * $magnitude)->first(fn ($s) => $s >= $rawStep) * $unit;
    if ($kind === 'count') { $step = max(1, (int) ceil($step)); }
    $axisMax = (int) ceil($max / $step) * $step;
    $n = count($series);
    $band = $plotW / max(1, $n);
    $barW = min(24, max(2, $band - 2));
    $maxIndex = array_search($max, array_column($series, 'minor'), true);
@endphp
<figure class="chart">
    <svg viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-labelledby="{{ $chartId }}-title" class="chart-svg">
        <title id="{{ $chartId }}-title">{{ $caption }}</title>
        @for ($v = 0; $v <= $axisMax; $v += $step)
            @php $y = $top + $plotH - ($v / $axisMax) * $plotH; @endphp
            <line x1="{{ $left }}" x2="{{ $w - $right }}" y1="{{ $y }}" y2="{{ $y }}" class="chart-grid"/>
            <text x="{{ $left - 8 }}" y="{{ $y + 4 }}" text-anchor="end" class="chart-axis">{{ number_format($v / $unit) }}</text>
        @endfor
        @foreach ($series as $i => $point)
            @php
                $x = $left + $i * $band + ($band - $barW) / 2;
                $bh = $point['minor'] > 0 ? max(1, ($point['minor'] / $axisMax) * $plotH) : 0;
                $y = $top + $plotH - $bh;
                $r = min(4, $bh, $barW / 2);
            @endphp
            @if ($bh > 0)
                <path class="chart-bar" d="M{{ $x }},{{ $top + $plotH }} V{{ $y + $r }} Q{{ $x }},{{ $y }} {{ $x + $r }},{{ $y }} H{{ $x + $barW - $r }} Q{{ $x + $barW }},{{ $y }} {{ $x + $barW }},{{ $y + $r }} V{{ $top + $plotH }} Z">
                    <title>{{ $point['date'] }}: {{ $fmt($point['minor']) }}</title>
                </path>
            @endif
            @if ($i === $maxIndex && $point['minor'] > 0)
                @php
                    // Keep the label inside the plot: anchor it inward near the edges.
                    [$lx, $anchor] = match (true) {
                        $x + $barW / 2 > $w - $right - 60 => [$x + $barW, 'end'],
                        $x + $barW / 2 < $left + 60 => [$x, 'start'],
                        default => [$x + $barW / 2, 'middle'],
                    };
                @endphp
                <text x="{{ $lx }}" y="{{ $y - 6 }}" text-anchor="{{ $anchor }}" class="chart-label">{{ $fmt($point['minor']) }}</text>
            @endif
            @if ($i === 0 || $i === $n - 1 || ($n > 14 && $i % 7 === 0 && $i < $n - 3))
                <text x="{{ $left + $i * $band + $band / 2 }}" y="{{ $h - 10 }}" text-anchor="middle" class="chart-axis">{{ \Illuminate\Support\Carbon::parse($point['date'])->translatedFormat('M j') }}</text>
            @endif
        @endforeach
        <line x1="{{ $left }}" x2="{{ $w - $right }}" y1="{{ $top + $plotH }}" y2="{{ $top + $plotH }}" class="chart-baseline"/>
    </svg>
    <figcaption class="muted">{{ $caption }}. {{ $kind === 'count' ? __('Hover a column for the exact value, or see the table.') : __('Axis in :currency; hover a column for the exact value, or see the table.', ['currency' => $currency]) }}</figcaption>
</figure>

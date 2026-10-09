@extends('layouts.app')

@section('title', __('Announcements - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Announcements') }}</h1>
    <p>{{ __('Active announcements appear above every page (at most two, newest first).') }}</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Title') }}</th><th scope="col">{{ __('Window') }}</th><th scope="col">{{ __('Visible') }}</th><th scope="col">{{ __('Actions') }}</th></tr></thead>
            <tbody>
                @forelse ($announcements as $a)
                    <tr>
                        <td><strong>{{ $a->title }}</strong><br>{{ \Illuminate\Support\Str::limit($a->body, 120) }}</td>
                        <td>{{ __(':start to :end', ['start' => $a->starts_at?->format('Y-m-d H:i') ?? __('now'), 'end' => $a->ends_at?->format('Y-m-d H:i') ?? __('open end')]) }}</td>
                        <td>{{ $a->active ? __('Yes') : __('No') }}</td>
                        <td class="actions">
                            <form method="post" action="{{ route('admin.announcements.toggle', $a) }}">
                                @csrf
                                <button type="submit" class="btn-secondary">{{ $a->active ? __('Hide') : __('Show') }}<span class="visually-hidden"> {{ $a->title }}</span></button>
                            </form>
                            <form method="post" action="{{ route('admin.announcements.destroy', $a) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger">{{ __('Delete') }}<span class="visually-hidden"> {{ $a->title }}</span></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">{{ __('No announcements.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $announcements->links() }}

    <h2>{{ __('New announcement') }}</h2>
    <form method="post" action="{{ route('admin.announcements.store') }}" class="stack">
        @csrf
        <x-field name="title" :label="__('Title')" maxlength="160" required />
        <x-textarea name="body" :label="__('Text')" maxlength="2000" required />
        <x-field name="starts_at" :label="__('Show from')" type="datetime-local" />
        <x-field name="ends_at" :label="__('Show until')" type="datetime-local" />
        <button type="submit">{{ __('Publish') }}</button>
    </form>
@endsection

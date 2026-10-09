@extends('layouts.app')

@section('title', 'Announcements - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Announcements</h1>
    <p>Active announcements appear above every page (at most two, newest first).</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Title</th><th scope="col">Window</th><th scope="col">Visible</th><th scope="col">Actions</th></tr></thead>
            <tbody>
                @forelse ($announcements as $a)
                    <tr>
                        <td><strong>{{ $a->title }}</strong><br>{{ \Illuminate\Support\Str::limit($a->body, 120) }}</td>
                        <td>{{ $a->starts_at?->format('Y-m-d H:i') ?? 'now' }} to {{ $a->ends_at?->format('Y-m-d H:i') ?? 'open end' }}</td>
                        <td>{{ $a->active ? 'Yes' : 'No' }}</td>
                        <td class="actions">
                            <form method="post" action="{{ route('admin.announcements.toggle', $a) }}">
                                @csrf
                                <button type="submit" class="btn-secondary">{{ $a->active ? 'Hide' : 'Show' }}<span class="visually-hidden"> {{ $a->title }}</span></button>
                            </form>
                            <form method="post" action="{{ route('admin.announcements.destroy', $a) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-danger">Delete<span class="visually-hidden"> {{ $a->title }}</span></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">No announcements.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $announcements->links() }}

    <h2>New announcement</h2>
    <form method="post" action="{{ route('admin.announcements.store') }}" class="stack">
        @csrf
        <x-field name="title" label="Title" maxlength="160" required />
        <x-textarea name="body" label="Text" maxlength="2000" required />
        <x-field name="starts_at" label="Show from" type="datetime-local" />
        <x-field name="ends_at" label="Show until" type="datetime-local" />
        <button type="submit">Publish</button>
    </form>
@endsection

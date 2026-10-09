@extends('layouts.app')

@section('title', __('Users - Admin'))
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>{{ __('Users') }}</h1>
    <form class="filters" method="get">
        <x-field name="q" :label="__('Email or name')" :value="$filters['q'] ?? ''" maxlength="100" />
        <x-select name="role" :label="__('Role')" :options="collect(\App\Enums\UserRole::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all()" :value="$filters['role'] ?? ''" :placeholder="__('Any role')" />
        <button type="submit">{{ __('Filter') }}</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">{{ __('Email') }}</th><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Role') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col" class="num">{{ __('Balance') }}</th><th scope="col">{{ __('Registered') }}</th></tr></thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td><a href="{{ route('admin.users.show', $user) }}">{{ $user->email }}</a></td>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->role->label() }}</td>
                        <td><x-status :value="$user->status" /></td>
                        <td class="num">{{ money($user->balance_minor, $user->currency) }}</td>
                        <td>{{ $user->created_at->format('Y-m-d') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    {{ $users->links() }}
@endsection

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
    @can('users.manage')
        <form id="bulk-users" method="post" action="{{ route('admin.bulk.users') }}" class="filters" aria-label="{{ __('Bulk action on users') }}">
            @csrf
            <input type="hidden" name="filter_q" value="{{ $filters['q'] ?? '' }}">
            <input type="hidden" name="filter_role" value="{{ $filters['role'] ?? '' }}">
            <x-select name="action" id="bulk-action" :label="__('Bulk action')" :options="['suspended' => __('Suspend accounts'), 'active' => __('Reactivate accounts')]" />
            <x-select name="scope" id="bulk-scope" :label="__('Apply to')" :options="['selected' => __('Ticked users'), 'filtered' => __('All users matching the filter (:count)', ['count' => $users->total()])]" />
            <button type="submit" class="btn-secondary">{{ __('Apply') }}</button>
        </form>
        <p class="hint">{{ __('Suspended users are signed out at once. Your own account and (unless you are a super admin) staff accounts are skipped.') }}</p>
    @endcan
    <div class="table-wrap">
        <table>
            <thead><tr>@can('users.manage')<th scope="col"><span class="visually-hidden">{{ __('Select') }}</span></th>@endcan<th scope="col">{{ __('Email') }}</th><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Role') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col" class="num">{{ __('Balance') }}</th><th scope="col">{{ __('Registered') }}</th></tr></thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        @can('users.manage')<td><input type="checkbox" name="ids[]" value="{{ $user->id }}" form="bulk-users" aria-label="{{ __('Select :email', ['email' => $user->email]) }}"></td>@endcan
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

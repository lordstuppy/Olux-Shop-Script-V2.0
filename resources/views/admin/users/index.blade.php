@extends('layouts.app')

@section('title', 'Users - Admin')
@section('noindex', true)
@section('subnav') @include('partials.admin-nav') @endsection

@section('content')
    <h1>Users</h1>
    <form class="filters" method="get">
        <x-field name="q" label="Email or name" :value="$filters['q'] ?? ''" maxlength="100" />
        <x-select name="role" label="Role" :options="['buyer' => 'Buyer', 'seller' => 'Seller', 'admin' => 'Admin']" :value="$filters['role'] ?? ''" placeholder="Any role" />
        <button type="submit">Filter</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th scope="col">Email</th><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col" class="num">Balance</th><th scope="col">Registered</th></tr></thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td><a href="{{ route('admin.users.show', $user) }}">{{ $user->email }}</a></td>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->role->value }}</td>
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

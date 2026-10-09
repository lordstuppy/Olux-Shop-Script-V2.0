<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffRolesTest extends TestCase
{
    private function staff(UserRole $role): User
    {
        $user = User::factory()->staff($role)->create();
        $this->actingAs($user)->confirmPassword();

        return $user;
    }

    /** @return array<string, array{0: UserRole, 1: list<string>, 2: list<string>}> */
    public static function tiers(): array
    {
        return [
            'super admin' => [UserRole::Admin, ['/admin', '/admin/settings', '/admin/exports', '/admin/payouts', '/admin/reviews', '/admin/audit'], []],
            'manager' => [UserRole::Manager, ['/admin', '/admin/orders', '/admin/payouts', '/admin/products', '/admin/sellers', '/admin/reports', '/admin/audit', '/admin/reviews'],
                ['/admin/settings', '/admin/exchange-rates', '/admin/coupons', '/admin/gift-cards']],
            'finance' => [UserRole::Finance, ['/admin', '/admin/payments', '/admin/payouts', '/admin/exchange-rates', '/admin/exports'],
                ['/admin/settings', '/admin/products', '/admin/reviews', '/admin/audit']],
            'moderator' => [UserRole::Moderator, ['/admin', '/admin/products', '/admin/categories', '/admin/reviews', '/admin/tickets', '/admin/announcements'],
                ['/admin/settings', '/admin/payouts', '/admin/payments', '/admin/exports', '/admin/reports', '/admin/sellers', '/admin/audit']],
            'support' => [UserRole::Support, ['/admin', '/admin/tickets', '/admin/orders', '/admin/users'],
                ['/admin/settings', '/admin/products', '/admin/payouts', '/admin/exports']],
        ];
    }

    #[DataProvider('tiers')]
    public function test_each_tier_reaches_only_its_pages(UserRole $role, array $allowed, array $denied): void
    {
        $this->staff($role);
        foreach ($allowed as $path) {
            $this->get($path)->assertOk();
        }
        foreach ($denied as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_only_super_admin_changes_roles(): void
    {
        $target = User::factory()->create();

        $this->staff(UserRole::Manager);
        $this->postForm(route('admin.users.role', $target), ['role' => 'moderator'])->assertForbidden();
        $this->get(route('admin.users.show', $target))->assertOk()->assertDontSee('Change role');

        $this->staff(UserRole::Admin);
        $this->get(route('admin.users.show', $target))->assertSee('Change role');
        $this->postForm(route('admin.users.role', $target), ['role' => 'moderator'])->assertSessionHas('success', $target->email.' is now moderator.');
        $this->assertSame(UserRole::Moderator, $target->fresh()->role);
    }

    public function test_manager_suspends_customers_but_not_staff(): void
    {
        $buyer = User::factory()->create();
        $colleague = User::factory()->staff(UserRole::Finance)->create();

        $this->staff(UserRole::Manager);
        $this->postForm(route('admin.users.status', $buyer), ['status' => 'suspended'])->assertSessionHas('success');
        $this->assertSame(UserStatus::Suspended, $buyer->fresh()->status);

        $this->get(route('admin.users.show', $colleague))->assertDontSee('Suspend account');
        $this->postForm(route('admin.users.status', $colleague), ['status' => 'suspended'])
            ->assertSessionHas('error', 'Only a super admin can change the status of a staff account.');
        $this->assertSame(UserStatus::Active, $colleague->fresh()->status);
    }

    public function test_new_staff_roles_need_two_factor(): void
    {
        foreach ([UserRole::Manager, UserRole::Moderator] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get('/admin')->assertRedirect(route('account.two-factor'));
        }
    }

    public function test_admin_role_is_labelled_super_admin(): void
    {
        $this->assertSame('Super admin', UserRole::Admin->label());
        $this->assertSame('Manager', UserRole::Manager->label());
    }
}

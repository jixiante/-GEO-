<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApiTokensPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_localized_api_token_management_copy(): void
    {
        $admin = Admin::query()->create([
            'username' => 'api_token_localization_admin',
            'password' => 'secret-123',
            'email' => 'api-token-localization@example.com',
            'display_name' => 'API Token Localization Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.api-tokens.index'))
            ->assertOk()
            ->assertSee(__('admin.nav.api_tokens'))
            ->assertSee(__('admin.api_tokens.page_heading'))
            ->assertSee(__('admin.api_tokens.section.create'))
            ->assertSee(__('admin.api_tokens.section.list'))
            ->assertSee(__('admin.api_tokens.field.scopes'))
            ->assertDontSee('API Tokens')
            ->assertDontSee('创建 Token')
            ->assertDontSee('现有 Tokens')
            ->assertDontSee('Scopes *');
    }
}

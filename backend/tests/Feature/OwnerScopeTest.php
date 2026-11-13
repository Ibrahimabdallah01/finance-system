<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_logged_in_user_only_sees_their_own_rows(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $a->business()->create(['name' => 'A Phones']);
        $b->business()->create(['name' => 'B Phones']);

        $this->actingAs($a);

        $this->assertSame(['A Phones'], Business::query()->pluck('name')->all());
    }
}

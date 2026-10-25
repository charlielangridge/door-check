<?php

use App\Models\Site;
use App\Models\User;

test('staff sign in once and are sent to their board', function (): void {
    $staff = User::factory()->hasAttached(Site::factory(), [], 'staffSites')->create();
    $staff->assignRole('staff');

    $this->post('/login', ['email' => $staff->email, 'password' => 'password'])
        ->assertRedirectToRoute('staff.index')->assertSessionHas('staff_signed_in_at');

    $this->assertAuthenticatedAs($staff);
    $this->assertDatabaseHas('audits', ['event' => 'login', 'user_id' => $staff->id]);
});

test('staff do not expire at seven in the evening', function (): void {
    $this->travelTo('2026-07-01 18:05:00');
    $staff = User::factory()->hasAttached(Site::factory(), [], 'staffSites')->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->subHours(13)->timestamp])->get(route('staff.board', $staff->staffSites()->first()->uuid))->assertOk();

    $this->assertAuthenticatedAs($staff);
});

test('yesterday staff session requires a new morning login', function (): void {
    $this->travelTo('2026-07-01 05:05:00');
    $staff = User::factory()->hasAttached(Site::factory(), [], 'staffSites')->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->subHours(10)->timestamp])->get(route('staff.board', $staff->staffSites()->first()->uuid))->assertRedirectToRoute('login');

    $this->assertGuest();
    $this->assertDatabaseHas('audits', ['event' => 'logout', 'user_id' => $staff->id]);
});

test('a staff permission without an assigned venue does not grant board access', function (): void {
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->withSession(['staff_signed_in_at' => now()->timestamp])->get(route('staff.index'))->assertForbidden();
});

test('staff permission does not grant Filament admin access', function (): void {
    $staff = User::factory()->hasAttached(Site::factory(), [], 'staffSites')->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)->get('/admin')->assertForbidden();

    $this->assertDatabaseHas('audits', ['event' => 'action_denied', 'user_id' => $staff->id]);
});

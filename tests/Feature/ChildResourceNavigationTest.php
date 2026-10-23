<?php

use App\Filament\Resources\Children\ChildResource;
use Filament\Facades\Filament;

test('the admin panel discovers the children resource', function (): void {
    expect(Filament::getPanel('admin')->getResources())->toContain(ChildResource::class);
});

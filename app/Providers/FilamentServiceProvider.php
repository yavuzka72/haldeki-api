<?php

namespace App\Providers;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Illuminate\Support\ServiceProvider;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Auth;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;

class FilamentServiceProvider extends ServiceProvider {
	public function boot(): void {
		// Filament'in varsayılan ayarlarını kullanıyoruz
	}
}

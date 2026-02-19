<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use App\Filament\Widgets\DashboardStats;
use App\Filament\Widgets\LatestOrders;
use App\Filament\Widgets\TopProducts;

class Dashboard extends BaseDashboard
{
    protected function getHeaderWidgets(): array
    {
        return [
            DashboardStats::class,
        ];
    }

    public function getWidgets(): array
    {
        return [
            LatestOrders::class,
            TopProducts::class,
        ];
    }
} 
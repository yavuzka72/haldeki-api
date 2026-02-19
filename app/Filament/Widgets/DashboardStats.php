<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use App\Models\Order;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardStats extends BaseWidget
{
    protected static ?string $pollingInterval = '15s';
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $totalSales = Order::whereMonth('created_at', now()->month)->sum('total_amount');
        $dailySales = Order::whereDate('created_at', today())->sum('total_amount');
        $orderCount = Order::whereMonth('created_at', now()->month)->count();

        return [
            Stat::make('Aylık Ciro', '₺' . number_format($totalSales, 2))
                ->description('Bu ayki toplam satış')
                ->descriptionIcon('heroicon-m-banknotes')
                ->chart([7, 3, 4, 5, 6, 3, 5, 3])
                ->color('success'),

            Stat::make('Günlük Ciro', '₺' . number_format($dailySales, 2))
                ->description('Bugünkü toplam satış')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->chart([3, 5, 3, 4, 5, 6, 3, 5])
                ->color('warning'),

            Stat::make('Aylık Sipariş', $orderCount)
                ->description('Bu ay alınan sipariş sayısı')
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->chart([5, 3, 4, 5, 6, 3, 5, 3])
                ->color('info'),
        ];
    }
} 
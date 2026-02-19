<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestOrders extends BaseWidget
{
    protected static ?string $heading = 'Son Siparişler';
    protected int|string|array $columnSpan = '1';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->latest()
                    ->limit(5)
            )
            ->columns([
                TextColumn::make('order_number')
                    ->label('Sipariş No')
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('Müşteri')
                    ->searchable(),
                TextColumn::make('total_amount')
                    ->label('Tutar')
                    ->money('TRY'),
                TextColumn::make('status')
                    ->label('Durum'),
            ]);
    }
} 
<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Widgets\TableWidget as BaseWidget;

class TopProducts extends BaseWidget
{
    protected static ?string $heading = 'En Çok Satan Ürünler';
    protected int|string|array $columnSpan = '1'; // Yarım genişlik

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::query()
                    ->withCount('variants')
                    ->orderByDesc('variants_count')
                    ->limit(5)
            )
            ->columns([
                ImageColumn::make('image')
                    ->label('Görsel')
                    ->circular(),
                TextColumn::make('name')
                    ->label('Ürün')
                    ->searchable()
                    ->limit(30),
                TextColumn::make('variants_count')
                    ->label('Satış')
                    ->sortable(),
            ]);
    }
} 
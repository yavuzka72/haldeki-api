<?php

namespace App\Filament\Resources\UserProductPriceResource\Pages;

use App\Filament\Resources\UserProductPriceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUserProductPrices extends ListRecords
{
    protected static string $resource = UserProductPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

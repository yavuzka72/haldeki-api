<?php

namespace App\Filament\Resources\UserProductPriceResource\Pages;

use App\Filament\Resources\UserProductPriceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUserProductPrice extends EditRecord
{
    protected static string $resource = UserProductPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}

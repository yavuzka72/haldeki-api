<?php

namespace App\Filament\Resources\UserProductResource\Pages;

use App\Filament\Resources\UserProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUserProducts extends ListRecords
{
    protected static string $resource = UserProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

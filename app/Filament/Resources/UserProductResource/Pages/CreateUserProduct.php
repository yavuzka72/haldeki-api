<?php

namespace App\Filament\Resources\UserProductResource\Pages;

use App\Filament\Resources\UserProductResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateUserProduct extends CreateRecord
{
    protected static string $resource = UserProductResource::class;
}

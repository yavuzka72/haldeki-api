<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class VariantsRelationManager extends RelationManager {
	protected static string $relationship = 'variants';
	protected static ?string $title = 'Varyantlar';
	protected static ?string $modelLabel = 'Varyant';
	protected static ?string $pluralModelLabel = 'Varyantlar';

	public function form(Form $form): Form {
		return $form
			->schema([
				Forms\Components\TextInput::make('name')
					->label('Varyant Adı')
					->required()
					->maxLength(255)
					->placeholder('Örn: 1 Kilogram, Yarım Kilo, 1 Kasa'),
				Forms\Components\Toggle::make('active')
					->label('Aktif')
					->default(true),
			]);
	}

	public function table(Table $table): Table {
		return $table
			->recordTitleAttribute('name')
			->columns([
				Tables\Columns\TextColumn::make('name')
					->label('Varyant')
					->searchable(),
				Tables\Columns\TextColumn::make('average_price')
					->label('Ortalama Fiyat')
					->money('TRY')
					->sortable(),
				Tables\Columns\IconColumn::make('active')
					->label('Durum')
					->boolean(),
			])
			->filters([
				Tables\Filters\TernaryFilter::make('active')
					->label('Durum'),
			])
			->headerActions([
				Tables\Actions\CreateAction::make(),
			])
			->actions([
				Tables\Actions\EditAction::make(),
				Tables\Actions\DeleteAction::make(),
			])
			->bulkActions([
				Tables\Actions\BulkActionGroup::make([
					Tables\Actions\DeleteBulkAction::make(),
				]),
			]);
	}
}

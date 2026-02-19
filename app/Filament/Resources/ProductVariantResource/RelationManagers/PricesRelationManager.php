<?php

namespace App\Filament\Resources\ProductVariantResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PricesRelationManager extends RelationManager {
	protected static string $relationship = 'prices';
	protected static ?string $title = 'Fiyatlar';
	protected static ?string $modelLabel = 'Fiyat';
	protected static ?string $pluralModelLabel = 'Fiyatlar';

	public function form(Form $form): Form {
		return $form
			->schema([
				Forms\Components\Select::make('user_id')
					->label('Kullanıcı')
					->relationship('user', 'name')
					->required()
					->searchable()
					->preload(),
				Forms\Components\TextInput::make('price')
					->label('Fiyat')
					->required()
					->numeric()
					->prefix('₺')
					->step(0.01),
				Forms\Components\Toggle::make('active')
					->label('Aktif')
					->default(true),
			]);
	}

	public function table(Table $table): Table {
		return $table
			->recordTitleAttribute('price')
			->columns([
				Tables\Columns\TextColumn::make('user.name')
					->label('Kullanıcı')
					->searchable()
					->sortable(),
				Tables\Columns\TextColumn::make('price')
					->label('Fiyat')
					->money('TRY')
					->sortable(),
				Tables\Columns\IconColumn::make('active')
					->label('Durum')
					->boolean(),
				Tables\Columns\TextColumn::make('created_at')
					->label('Oluşturulma')
					->dateTime('d.m.Y H:i')
					->sortable(),
			])
			->filters([
				Tables\Filters\SelectFilter::make('user')
					->label('Kullanıcı')
					->relationship('user', 'name')
					->searchable()
					->preload(),
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

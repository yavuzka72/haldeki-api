<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ProductsRelationManager extends RelationManager {
	protected static string $relationship = 'products';
	protected static ?string $title = 'Ürünler';
	protected static ?string $modelLabel = 'Ürün';
	protected static ?string $pluralModelLabel = 'Ürünler';

	public function form(Form $form): Form {
		return $form
			->schema([
				Forms\Components\Select::make('product_id')
					->label('Ürün')
					->relationship('product', 'name')
					->required()
					->searchable()
					->preload(),
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
					->label('Ürün Adı')
					->searchable()
					->sortable(),
				Tables\Columns\ImageColumn::make('image')
					->label('Görsel'),
				Tables\Columns\TextColumn::make('variants_count')
					->label('Varyant Sayısı')
					->counts('variants'),
				Tables\Columns\IconColumn::make('active')
					->label('Durum')
					->boolean(),
				Tables\Columns\TextColumn::make('created_at')
					->label('Oluşturulma')
					->dateTime('d.m.Y H:i')
					->sortable(),
			])
			->filters([
				Tables\Filters\TernaryFilter::make('active')
					->label('Durum'),
			])
			->headerActions([
				Tables\Actions\AttachAction::make()
					->label('Ürün Ata')
					->preloadRecordSelect(),
			])
			->actions([
				Tables\Actions\DetachAction::make()
					->label('Ürün Bağlantısını Kaldır'),
			])
			->bulkActions([
				Tables\Actions\BulkActionGroup::make([
					Tables\Actions\DetachBulkAction::make()
						->label('Seçili Ürünlerin Bağlantısını Kaldır'),
				]),
			]);
	}
}

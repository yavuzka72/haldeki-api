<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserProductResource\Pages;
use App\Models\UserProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class UserProductResource extends Resource {
	protected static ?string $model = UserProduct::class;

	protected static ?string $navigationIcon = 'heroicon-o-user-group';

	protected static ?string $navigationGroup = 'Kullanıcı Yönetimi';

	protected static ?int $navigationSort = 4;

	protected static ?string $modelLabel = 'Kullanıcı Ürünü';

	protected static ?string $pluralModelLabel = 'Kullanıcı Ürünleri';

	public static function form(Form $form): Form {
		return $form
			->schema([
				Forms\Components\Section::make('Kullanıcı Ürün Ataması')
					->schema([
						Forms\Components\Select::make('user_id')
							->label('Kullanıcı')
							->relationship('user', 'name')
							->required()
							->searchable()
							->preload(),
						Forms\Components\Select::make('product_id')
							->label('Ürün')
							->relationship('product', 'name')
							->required()
							->searchable()
							->preload(),
						Forms\Components\Toggle::make('active')
							->label('Aktif')
							->default(true),
					])
					->columns(2),
			]);
	}

	public static function table(Table $table): Table {
		return $table
			->columns([
				Tables\Columns\TextColumn::make('user.name')
					->label('Kullanıcı')
					->searchable()
					->sortable(),
				Tables\Columns\TextColumn::make('product.name')
					->label('Ürün')
					->searchable()
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
				Tables\Filters\SelectFilter::make('product')
					->label('Ürün')
					->relationship('product', 'name')
					->searchable()
					->preload(),
				Tables\Filters\TernaryFilter::make('active')
					->label('Durum'),
			])
			->actions($table->getActions())
			->bulkActions([
				Tables\Actions\BulkActionGroup::make([
					Tables\Actions\DeleteBulkAction::make(),
				]),
			]);
	}

	public static function getPages(): array {
		return [
			'index' => Pages\ListUserProducts::route('/'),
			'create' => Pages\CreateUserProduct::route('/create'),
			'edit' => Pages\EditUserProduct::route('/{record}/edit'),
		];
	}

	public static function shouldRegisterNavigation(): bool {
		// Hem süper admin hem de satıcılar görebilir
		return in_array(Auth::user()?->admin_level, [1, 2]);
	}

	public static function getEloquentQuery(): Builder {
		$query = parent::getEloquentQuery();

		// Satıcılar sadece kendilerine atanan ürünleri görsün
		if (Auth::user()?->admin_level === 2) {
			$query->where('user_id', Auth::id());
		}

		return $query;
	}

	// Form için yetkileri ayarlayalım
	protected function getTableActions(): array {
		// Süper admin tüm işlemleri yapabilir
		if (Auth::user()?->admin_level === 1) {
			return [
				Tables\Actions\EditAction::make(),
				Tables\Actions\DeleteAction::make(),
			];
		}

		// Satıcılar sadece görüntüleyebilir
		return [];
	}
}

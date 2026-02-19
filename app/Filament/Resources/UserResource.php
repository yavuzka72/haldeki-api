<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
 

class UserResource extends Resource {
	protected static ?string $model = User::class;

	protected static ?string $navigationIcon = 'heroicon-o-users';

	protected static ?string $navigationGroup = 'Kullanıcı Yönetimi';

	protected static ?int $navigationSort = 1;

	protected static ?string $modelLabel = 'Kullanıcı';

	protected static ?string $pluralModelLabel = 'Kullanıcılar';

	public static function form(Form $form): Form {
		return $form
			->schema([
				Forms\Components\Section::make('Kullanıcı Bilgileri')
					->schema([
						Forms\Components\TextInput::make('name')
							->label('Ad Soyad')
							->required()
							->maxLength(255),
						Forms\Components\TextInput::make('email')
							->label('E-posta')
							->email()
							->required()
							->maxLength(255)
							->unique(ignoreRecord: true),
						Forms\Components\TextInput::make('password')
							->label('Şifre')
							->password()
							->dehydrateStateUsing(fn($state) => Hash::make($state))
							->dehydrated(fn($state) => filled($state))
							->required(fn(string $context): bool => $context === 'create'),
					])
					->columns(2),
				Forms\Components\Section::make('Yetkilendirme')
					->schema([
						Forms\Components\Toggle::make('admin')
							->label('Admin Paneline Erişim')
							->default(false)
							->live(),
						Forms\Components\Select::make('admin_level')
							->label('Yetki Seviyesi')
							->options([
								0 => 'Normal Kullanıcı',
								1 => 'Süper Admin',
								2 => 'Satıcı',
							])
							->default(0)
							->hidden(fn(Forms\Get $get): bool => !$get('admin')),
					])
					->columns(2),
			]);
	}

	public static function table(Table $table): Table {
		return $table
			->columns([
				Tables\Columns\TextColumn::make('name')
					->label('Ad Soyad')
					->searchable()
					->sortable(),
				Tables\Columns\TextColumn::make('email')
					->label('E-posta')
					->searchable()
					->sortable(),
				Tables\Columns\IconColumn::make('admin')
					->label('Admin')
					->boolean(),
				Tables\Columns\TextColumn::make('admin_level')
					->label('Yetki')
					->formatStateUsing(fn(int $state): string => match ($state) {
						0 => 'Normal Kullanıcı',
						1 => 'Süper Admin',
						2 => 'Satıcı',
						default => 'Bilinmiyor',
					}),
				Tables\Columns\TextColumn::make('created_at')
					->label('Kayıt Tarihi')
					->dateTime('d.m.Y H:i')
					->sortable(),
			])
			->filters([
				Tables\Filters\SelectFilter::make('admin_level')
					->label('Yetki')
					->options([
						0 => 'Normal Kullanıcı',
						1 => 'Süper Admin',
						2 => 'Satıcı',
					]),
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

	public static function getRelations(): array {
		return [
			RelationManagers\ProductsRelationManager::class,
		];
	}

	public static function getPages(): array {
		return [
			'index' => Pages\ListUsers::route('/'),
			'create' => Pages\CreateUser::route('/create'),
			'edit' => Pages\EditUser::route('/{record}/edit'),
		];
	}
}

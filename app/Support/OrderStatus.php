<?php

namespace App\Support;

final class OrderStatus
{
    public const PENDING   = 'pending';
    public const CONFIRMED = 'confirmed';
 
    public const CANCELLED = 'cancelled';

    public static function toTr(string $en): string
    {
        return match (strtolower($en)) {
            self::PENDING   => 'Bekliyor',
            self::CONFIRMED => 'Onayland覺',
        
            self::CANCELLED => 'iptal',
            default         => $en,
        };
    }

    public static function toEn(string $tr): string
    {
        $t = mb_strtolower(trim($tr));
        return match (true) {
            str_contains($t, 'Bekliyor')  => self::PENDING,
            str_contains($t, 'Onay')   => self::CONFIRMED,
           
            str_contains($t, 'iptal')  => self::CANCELLED,
            $t === 'pending' => self::PENDING,
            in_array($t, ['confirm','confirmed','approved','accepted']) => self::CONFIRMED,
            
            in_array($t, ['cancel','canceled','cancelled'])             => self::CANCELLED,
            default => self::PENDING,
        };
    }


  public static function toEnStatus(string $input): string
    {
        $t = trim(mb_strtolower($input, 'UTF-8'));

        // Türkçe varyasyonlar
        $pending  = ['hazırlanıyor','hazirlaniyor','bekliyor','beklemede','hazırlık','hazirlik','pending','prepare','preparing'];
        $confirmed= ['onay','onayli','onaylı','onaylandı','onaylandi','onay verildi',
                     'sevk','sevk edildi','yola çıktı','yola cikti',
                     'confirmed','confirm','approved','accepted','shipped'];
        $cancelled= ['iptal','iptal edildi','vazgecildi','vazgeçildi','cancel','canceled','cancelled'];

        if (in_array($t, $pending, true))   return 'pending';
        if (in_array($t, $confirmed, true)) return 'confirmed';
        if (in_array($t, $cancelled, true)) return 'cancelled';

        // Eğer zaten EN üçlemesinden biri geldiyse olduğu gibi bırak
        if (in_array($t, ['pending','confirmed','cancelled'], true)) {
            return $t;
        }

        // Tanınmazsa güvenli varsayılan
        return 'pending';
    }

    /** DB EN -> UI TR */
    public static function toTrStatus(string $en): string
    {
        return match (mb_strtolower($en, 'UTF-8')) {
            'pending'   => 'hazırlanıyor',
            'confirmed' => 'onaylandı',
            'cancelled' => 'iptal',
            default     => $en,
        };
    }
    public static function allowed(): array
    {
        return [self::PENDING,self::CONFIRMED,self::AWAY,self::DELIVERED,self::CANCELLED];
    }
}

<?php

namespace App\Service;

use Symfony\Component\Intl\Countries;

class CountryNameResolver
{
    /**
     * FIFA codes that differ from ISO alpha-3.
     *
     * @var array<string, string>
     */
    private const FIFA_TO_ISO3 = [
        'ENG' => 'GBR',
        'SCO' => 'GBR',
        'KSA' => 'SAU',
        'RSA' => 'ZAF',
    ];

    /**
     * Explicit short Spanish names for long or special cases.
     *
     * @var array<string, string>
     */
    private const SHORT_NAME_OVERRIDES = [
        'ALG' => 'Argelia',
        'BIH' => 'Bosnia y Herz.',
        'COD' => 'Congo RD',
        'CZE' => 'Chequia',
        'ENG' => 'Inglaterra',
        'IRN' => 'Iran',
        'KOR' => 'Corea del Sur',
        'KSA' => 'Arabia Saudita',
        'MAR' => 'Marruecos',
        'NLD' => 'Paises Bajos',
        'NZL' => 'Nueva Zelanda',
        'RSA' => 'Sudafrica',
        'SCO' => 'Escocia',
        'TUR' => 'Turquia',
        'USA' => 'EE. UU.',
    ];

    public function fifaToIso3(string $countryCode): string
    {
        $normalized = strtoupper(trim($countryCode));

        return self::FIFA_TO_ISO3[$normalized] ?? $normalized;
    }

    public function resolveSpanishName(?string $countryCode, ?string $fallbackName = null): string
    {
        if (null === $countryCode || '' === trim($countryCode)) {
            return $fallbackName ?? '';
        }

        $normalized = strtoupper(trim($countryCode));

        if (isset(self::SHORT_NAME_OVERRIDES[$normalized])) {
            return self::SHORT_NAME_OVERRIDES[$normalized];
        }

        $alpha3 = $this->fifaToIso3($normalized);

        if (Countries::alpha3CodeExists($alpha3)) {
            $alpha2 = Countries::getAlpha2Code($alpha3);
            $name = Countries::getName($alpha2, 'es');
            if (null !== $name && '' !== trim($name)) {
                return $name;
            }
        }

        return $fallbackName ?? $normalized;
    }
}
<?php

namespace App\Twig;

use App\Service\CountryNameResolver;
use Symfony\Component\Intl\Countries;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class FlagExtension extends AbstractExtension
{
    public function __construct(private readonly CountryNameResolver $countryNameResolver)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('flag_emoji', [$this, 'flagEmoji']),
            new TwigFilter('country_name_es', [$this, 'countryNameEs']),
        ];
    }

    public function flagEmoji(?string $countryCode): string
    {
        if (null === $countryCode || '' === trim($countryCode)) {
            return '';
        }

        $alpha3 = $this->countryNameResolver->fifaToIso3($countryCode);

        if (!Countries::alpha3CodeExists($alpha3)) {
            return '';
        }

        $alpha2 = Countries::getAlpha2Code($alpha3);

        return $this->alpha2ToFlagEmoji($alpha2);
    }

    private function alpha2ToFlagEmoji(string $alpha2): string
    {
        $alpha2 = strtoupper($alpha2);

        if (!preg_match('/^[A-Z]{2}$/', $alpha2)) {
            return '';
        }

        $first = mb_chr(0x1F1E6 + (ord($alpha2[0]) - ord('A')), 'UTF-8');
        $second = mb_chr(0x1F1E6 + (ord($alpha2[1]) - ord('A')), 'UTF-8');

        return $first.$second;
    }

    public function countryNameEs(?string $countryCode, ?string $fallbackName = null): string
    {
        return $this->countryNameResolver->resolveSpanishName($countryCode, $fallbackName);
    }
}
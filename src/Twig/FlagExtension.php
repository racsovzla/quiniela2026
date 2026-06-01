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
            new TwigFilter('flag_icon', [$this, 'flagIcon'], ['is_safe' => ['html']]),
            new TwigFilter('country_name_es', [$this, 'countryNameEs']),
        ];
    }

    public function flagEmoji(?string $countryCode): string
    {
        $alpha2 = $this->resolveAlpha2($countryCode);

        if (null === $alpha2) {
            return '';
        }

        return $this->alpha2ToFlagEmoji($alpha2);
    }

    public function flagIcon(?string $countryCode): string
    {
        $alpha2 = $this->resolveAlpha2($countryCode);

        if (null === $alpha2) {
            return '';
        }

        $emoji = $this->alpha2ToFlagEmoji($alpha2);
        $alpha2Lower = strtolower($alpha2);
        $code = strtoupper(trim((string) $countryCode));
        $alt = htmlspecialchars('Bandera ' . $code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $emojiEscaped = htmlspecialchars($emoji, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            '<span class="flag-icon-wrap"><img class="flag-icon" src="https://flagcdn.com/24x18/%s.png" alt="%s" width="24" height="18" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'inline\';"><span class="flag-emoji-fallback" aria-hidden="true">%s</span></span>',
            $alpha2Lower,
            $alt,
            $emojiEscaped
        );
    }

    private function resolveAlpha2(?string $countryCode): ?string
    {
        if (null === $countryCode || '' === trim($countryCode)) {
            return null;
        }

        $alpha3 = $this->countryNameResolver->fifaToIso3($countryCode);

        if (!Countries::alpha3CodeExists($alpha3)) {
            return null;
        }

        return Countries::getAlpha2Code($alpha3);
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
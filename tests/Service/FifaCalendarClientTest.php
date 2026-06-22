<?php

namespace App\Tests\Service;

use App\Service\FifaCalendarClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

class FifaCalendarClientTest extends TestCase
{
    private FifaCalendarClient $client;

    protected function setUp(): void
    {
        $this->client = new FifaCalendarClient(new MockHttpClient());
    }

    public function testHasBothTeamsConfirmedWhenBothSidesAreRealTeams(): void
    {
        $row = [
            'Home' => ['Abbreviation' => 'GER'],
            'Away' => ['Abbreviation' => 'ARG'],
        ];

        self::assertTrue($this->client->hasBothTeamsConfirmed($row));
    }

    public function testHasBothTeamsConfirmedIsFalseWhenAwayIsNull(): void
    {
        $row = [
            'Home' => ['Abbreviation' => 'GER'],
            'Away' => null,
            'Date' => '2026-06-29T20:30:00Z',
        ];

        self::assertFalse($this->client->hasBothTeamsConfirmed($row));
    }

    public function testHasBothTeamsConfirmedIsFalseWhenPlaceholderExists(): void
    {
        $row = [
            'Home' => ['Abbreviation' => 'GER'],
            'Away' => ['Abbreviation' => 'ARG'],
            'PlaceholderB' => 'W73',
        ];

        self::assertFalse($this->client->hasBothTeamsConfirmed($row));
    }

    public function testHasBothTeamsConfirmedIsFalseForBracketStyleCodes(): void
    {
        $row = [
            'Home' => ['Abbreviation' => 'GER'],
            'Away' => ['Abbreviation' => 'A1'],
        ];

        self::assertFalse($this->client->hasBothTeamsConfirmed($row));
    }
}

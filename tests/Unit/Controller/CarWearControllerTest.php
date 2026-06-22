<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Cache\Adapter\FilesystemCache;
use App\Controller\CarWearController;
use App\Http\Request;
use App\Repository\UserRepository;
use App\Security\Authorize;
use App\Service\CarWearService;
use App\Service\GproApiClient;
use App\Service\GproApiFetcher;
use App\Service\GproDataMapper;
use App\Support\RaceWindow;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversClass(CarWearController::class)]
final class CarWearControllerTest extends TestCase
{
    private const string TOKEN = 'test-token';

    private string $cacheDir;
    private FilesystemCache $cache;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/gpro_wear_ctrl_' . bin2hex(random_bytes(6));
        $this->cache = new FilesystemCache($this->cacheDir);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
        $_SESSION = [];
    }

    /** Cache keys the client namespaces by race window — must match here. */
    private const array RACE_WINDOWED = ['next_race_profile', 'car_data'];

    /** @param array<string, mixed> $value */
    private function seed(string $key, array $value): void
    {
        $cacheKey = 'u' . GproApiClient::scopeFor(self::TOKEN) . ':' . $key;
        if (in_array($key, self::RACE_WINDOWED, true)) {
            $window = RaceWindow::idFor(new DateTimeImmutable('now'), [2, 5], 0, 'Europe/London');
            $cacheKey .= ':w' . $window;
        }
        $this->cache->set($cacheKey, $value, 3600);
    }

    /** @param array<string, mixed> $carOverrides */
    private function seedRaceData(array $carOverrides = []): void
    {
        $this->seed('office_data', [
            'endOfSeason' => 0,
            'raceNb'      => 5,
            'seasonNb'    => 101,
            'trackName'   => 'Testopolis',
            'driId'       => 123,
        ]);
        $this->seed('next_race_profile', [
            'trackNotFoundNote' => false,
            'name'              => 'Testopolis',
            'laps'              => 50,
        ]);
        $this->seed('driver_profile_123', [
            'concentration' => 0,
            'talent'        => 0,
            'experience'    => 0,
        ]);
        $this->seed('car_data', array_replace([
            'lvlEngine' => 6,
            'usaEngine' => 22,
        ], $carOverrides));
    }

    private function controller(?Environment $twig = null): CarWearController
    {
        $api = new GproApiClient(
            new GproApiFetcher(['base_url' => 'http://127.0.0.1:9', 'version' => 'test']),
            $this->cache,
        );
        $api->setToken(self::TOKEN);

        return new CarWearController(
            new CarWearService($this->db(), [
                'driver_wear_factors' => [
                    'concentration' => 1.0,
                    'talent'        => 1.0,
                    'experience'    => 1.0,
                ],
                'part_level_factors' => [
                    1 => 1.05,
                    6 => 1.01,
                    9 => 1.0,
                ],
            ]),
            $api,
            new GproDataMapper(),
            $this->authorize(),
            $twig ?? new Environment(new ArrayLoader(['partials/_car_wear_results.twig' => ''])),
        );
    }

    private function authorize(): Authorize
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findById')->willReturn(['id' => 1, 'api_token' => self::TOKEN]);

        return new Authorize($users);
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec(
            "CREATE TABLE tracks (id INTEGER PRIMARY KEY, name TEXT, laps INTEGER,
             wear_chassis REAL, wear_engine REAL, wear_fwing REAL,
             wear_rwing REAL, wear_underbody REAL, wear_sidepod REAL,
             wear_cooling REAL, wear_gearbox REAL, wear_brakes REAL,
             wear_suspension REAL, wear_electronics REAL)"
        );
        $db->exec(
            "INSERT INTO tracks (id, name, laps, wear_chassis, wear_engine, wear_fwing,
             wear_rwing, wear_underbody, wear_sidepod, wear_cooling, wear_gearbox,
             wear_brakes, wear_suspension, wear_electronics)
             VALUES (1, 'Testopolis', 50, 0, 10, 0, 0, 0, 0, 0, 0, 0, 0, 0)"
        );

        return $db;
    }

    public function testApiOldPartChoiceIsSelectedAndProjected(): void
    {
        $this->seedRaceData([
            'engineOptions' => [
                ['disabled' => 'false', 'value' => ['value' => -1, 'cost' => 0], 'newLvl' => 5, 'newWear' => 48],
                ['disabled' => 'true', 'value' => ['value' => -2, 'cost' => 0], 'newLvl' => 4, 'newWear' => 20],
                ['disabled' => 'false', 'value' => ['value' => 1, 'cost' => 100], 'newLvl' => 7, 'newWear' => 0],
                ['disabled' => 'false', 'value' => ['value' => -3, 'cost' => 0], 'newLvl' => 'bad', 'newWear' => 10],
            ],
        ]);

        $result = $this->controller()->runCalc(0, [
            'engine' => ['choice' => 'old:0'],
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(5, $result['results']['parts']['Engine']['level']);
        $this->assertSame(48, $result['results']['parts']['Engine']['start']);
        $this->assertSame(58.0, $result['results']['parts']['Engine']['end']);
        $this->assertSame('old:0', $result['inputs']['parts']['engine']['choice']);
        $this->assertSame('old:0', $result['part_choices']['engine']['selected_choice']);
        $this->assertCount(1, $result['part_choices']['engine']['old_options']);
    }

    public function testManualSelectionClampsSubmittedEdgeValues(): void
    {
        $this->seedRaceData();

        $result = $this->controller()->runCalc(0, [
            'engine' => [
                'choice'       => 'manual',
                'manual_level' => '99',
                'manual_wear'  => '-5',
            ],
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(9, $result['results']['parts']['Engine']['level']);
        $this->assertSame(0, $result['results']['parts']['Engine']['start']);
        $this->assertSame(9, $result['inputs']['parts']['engine']['manual_level']);
        $this->assertSame(0, $result['inputs']['parts']['engine']['manual_wear']);
        $this->assertSame('manual', $result['part_choices']['engine']['selected_choice']);
    }

    public function testMissingApiOldPartOptionsExposeManualInputs(): void
    {
        $this->seedRaceData();

        $result = $this->controller()->runCalc(0);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame([], $result['part_choices']['engine']['old_options']);
        $this->assertTrue($result['part_choices']['engine']['show_manual']);
        $this->assertSame(['current', 'manual'], array_column($result['part_choices']['engine']['choices'], 'value'));
    }

    public function testFragmentRefreshPersistsSelectedPartChoices(): void
    {
        $this->seedRaceData([
            'engineOptions' => [
                ['disabled' => 'false', 'value' => ['value' => -1, 'cost' => 0], 'newLvl' => 5, 'newWear' => 48],
            ],
        ]);
        $_SESSION['user_id'] = 1;

        $twig = new Environment(new ArrayLoader([
            'partials/_car_wear_results.twig' => '{{ wear_inputs.parts.engine.choice }}',
        ]));

        ob_start();
        $this->controller($twig)->fragment(new Request([], [
            'risk' => '0',
            'parts' => [
                'engine' => ['choice' => 'old:0'],
            ],
        ], [], []));
        $output = ob_get_clean();

        $this->assertSame('old:0', $output);
        $this->assertSame('old:0', $_SESSION['wear_inputs']['parts']['engine']['choice']);
        $this->assertSame(5, $_SESSION['wear_results']['parts']['Engine']['level']);
    }
}

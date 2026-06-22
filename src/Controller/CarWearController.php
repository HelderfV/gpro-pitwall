<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Security\Authorize;
use App\Service\CarWearService;
use App\Service\GproApiClient;
use App\Service\GproDataMapper;
use Twig\Environment;

class CarWearController
{
    /**
     * Display labels from CarWearService::PARTS_MAP mapped to stable form keys.
     *
     * @var array<string, string>
     */
    public const array PART_SLUGS = [
        'Chassis'     => 'chassis',
        'Engine'      => 'engine',
        'Front Wing'  => 'fwing',
        'Rear Wing'   => 'rwing',
        'Underbody'   => 'underbody',
        'Sidepods'    => 'sidepods',
        'Cooling'     => 'cooling',
        'Gearbox'     => 'gear',
        'Brakes'      => 'brakes',
        'Suspension'  => 'susp',
        'Electronics' => 'electronics',
    ];

    public function __construct(
        private readonly CarWearService $service,
        private readonly GproApiClient $api,
        private readonly GproDataMapper $mapper,
        private readonly Authorize $authorize,
        private readonly Environment $twig,
    ) {
    }

    public function handle(Request $request): void
    {
        $user = $this->authorize->requireAuth();
        $this->api->setToken($user['api_token']);

        $risk = (int) $request->post('risk', 0);
        $risk = self::clampInt($risk, 0, 100);
        $submittedParts = $request->post('parts', []);
        $result = $this->runCalc($risk, is_array($submittedParts) ? $submittedParts : []);

        if (isset($result['error'])) {
            $_SESSION['wear_error'] = $result['error'];
            $inputs = $_SESSION['wear_inputs'] ?? [];
            $inputs = is_array($inputs) ? $inputs : [];
            $inputs['risk'] = $risk;
            $_SESSION['wear_inputs'] = $inputs;
        } else {
            $_SESSION['wear_results'] = $result['results'];
            $_SESSION['wear_inputs'] = $result['inputs'];
            $_SESSION['wear_error'] = null;
        }

        session_write_close();
        header('Location: /?main_tab=Car Wear');
        exit;
    }

    public function fragment(Request $request): void
    {
        $user = $this->authorize->requireAuth();
        $this->api->setToken($user['api_token']);

        $risk = (int) $request->post('risk', 0);
        $risk = self::clampInt($risk, 0, 100);
        $submittedParts = $request->post('parts', []);
        $result = $this->runCalc($risk, is_array($submittedParts) ? $submittedParts : []);

        if (!isset($result['error'])) {
            $_SESSION['wear_results'] = $result['results'];
            $_SESSION['wear_inputs'] = $result['inputs'];
            $_SESSION['wear_error'] = null;
        } else {
            $_SESSION['wear_error'] = $result['error'];
            $inputs = $_SESSION['wear_inputs'] ?? [];
            $inputs = is_array($inputs) ? $inputs : [];
            $inputs['risk'] = $risk;
            $_SESSION['wear_inputs'] = $inputs;
        }

        echo $this->twig->render('partials/_car_wear_results.twig', [
            'wear_results' => $result['results'] ?? null,
            'wear_error'   => $result['error'] ?? null,
            'wear_inputs'  => $result['inputs'] ?? ($_SESSION['wear_inputs'] ?? []),
        ]);
    }

    /**
     * Runs the wear calculation. Returns:
     *   ['results' => array, 'driver' => array]  on success
     *   ['error'   => string]                    on failure
     *
     * No session writes here — the same call powers the redirect-after-POST
     * flow, the no-reload slider refresh, and the auto-populate on first
     * tab open.
     *
     * @param array<string, mixed> $submittedParts
     * @return array<string, mixed>
     */
    public function runCalc(int $risk, array $submittedParts = []): array
    {
        try {
            $risk = self::clampInt($risk, 0, 100);
            $office       = $this->api->getOfficeData();
            $trackProfile = $this->api->getNextRaceProfile();

            // endOfSeason is the authoritative "no next race" signal — mirror
            // StrategyController. trackNotFoundNote alone is NOT reliable: GPRO
            // sets it at the start of a new season (race 1) while the track +
            // laps are already known but pre-race setup isn't done. Treating it
            // as end-of-season showed "Season finished" on a fresh season (#21,
            // regression). Only honour the note when there's genuinely no race.
            $endOfSeason     = !empty($office['endOfSeason']);
            $noRaceScheduled = (int) ($office['raceNb'] ?? 0) === 0;
            if ($endOfSeason || ($noRaceScheduled && !empty($trackProfile['trackNotFoundNote']))) {
                return ['error' => StrategyController::END_OF_SEASON_MESSAGE];
            }

            // No driver under contract: wear can't be projected. Point the user
            // at the Recruitment Analyzer (rendered as a special notice).
            if (!$this->api->hasPilot()) {
                return ['error' => StrategyController::NO_PILOT_MESSAGE];
            }

            $carData      = $this->api->getCarData();
            $driver       = $this->mapper->mapDriver($this->api->getMyPilotDetails());
            $partChoices  = self::buildPartChoices($carData);
            $selection    = self::parsePartSelections($submittedParts, $partChoices);

            if (empty($trackProfile['name']) && !empty($office['trackName'])) {
                $trackProfile['name'] = $office['trackName'];
            }

            $results = $this->service->calculateWear(
                $trackProfile,
                $carData,
                $driver,
                $risk,
                $selection['overrides'],
            );
            $results['season'] = $office['seasonNb'] ?? '?';
            $results['race']   = $office['raceNb'] ?? '?';

            return [
                'results'      => $results,
                'driver'       => $driver,
                'part_choices' => $selection['choices'],
                'inputs'       => [
                    'risk'   => $risk,
                    'driver' => $driver,
                    'parts'  => $selection['inputs'],
                ],
            ];
        } catch (\Exception $exception) {
            return ['error' => 'Error: ' . $exception->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $carData
     * @return array<string, array{
     *   label: string,
     *   slug: string,
     *   current: array{level: int, wear: int},
     *   old_options: list<array{value: string, label: string, level: int, wear: int}>,
     *   choices: list<array{value: string, label: string, level: int|null, wear: int|null}>,
     *   selected_choice: string,
     *   manual_level: int,
     *   manual_wear: int,
     *   show_manual: bool
     * }>
     */
    public static function buildPartChoices(array $carData): array
    {
        $choices = [];

        foreach (CarWearService::PARTS_MAP as $label => $map) {
            $slug = self::PART_SLUGS[$label];
            $currentLevel = (int) ($carData[$map['lvl']] ?? 1);
            $currentWear = (int) ($carData[$map['wear']] ?? 0);

            $oldOptions = [];
            $rawOptions = $carData[$map['options']] ?? [];
            if (is_array($rawOptions)) {
                foreach ($rawOptions as $rawOption) {
                    if (!is_array($rawOption) || self::isDisabledOption($rawOption)) {
                        continue;
                    }

                    $action = self::nestedNumeric($rawOption, ['value', 'value']);
                    $level = self::numericValue($rawOption['newLvl'] ?? null);
                    $wear = self::numericValue($rawOption['newWear'] ?? null);
                    if ($action === null || $action >= 0.0 || $level === null || $wear === null) {
                        continue;
                    }

                    $levelInt = (int) $level;
                    $wearInt = (int) $wear;
                    if ($levelInt < 1 || $levelInt > 9 || $wearInt < 0 || $wearInt > 100) {
                        continue;
                    }

                    $oldOptions[] = [
                        'value' => 'old:' . count($oldOptions),
                        'label' => sprintf('Old: L%d, %d%%', $levelInt, $wearInt),
                        'level' => $levelInt,
                        'wear'  => $wearInt,
                    ];
                }
            }

            $optionRows = [
                [
                    'value' => 'current',
                    'label' => sprintf('Current: L%d, %d%%', $currentLevel, $currentWear),
                    'level' => $currentLevel,
                    'wear'  => $currentWear,
                ],
                ...$oldOptions,
                [
                    'value' => 'manual',
                    'label' => 'Manual',
                    'level' => null,
                    'wear'  => null,
                ],
            ];

            $choices[$slug] = [
                'label'           => $label,
                'slug'            => $slug,
                'current'         => ['level' => $currentLevel, 'wear' => $currentWear],
                'old_options'     => $oldOptions,
                'choices'         => $optionRows,
                'selected_choice' => 'current',
                'manual_level'    => $currentLevel,
                'manual_wear'     => $currentWear,
                'show_manual'     => $oldOptions === [],
            ];
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $submittedParts
     * @param array<string, array{
     *   label: string,
     *   slug: string,
     *   current: array{level: int, wear: int},
     *   old_options: list<array{value: string, label: string, level: int, wear: int}>,
     *   choices: list<array{value: string, label: string, level: int|null, wear: int|null}>,
     *   selected_choice: string,
     *   manual_level: int,
     *   manual_wear: int,
     *   show_manual: bool
     * }> $partChoices
     * @return array{
     *   choices: array<string, array{
     *     label: string,
     *     slug: string,
     *     current: array{level: int, wear: int},
     *     old_options: list<array{value: string, label: string, level: int, wear: int}>,
     *     choices: list<array{value: string, label: string, level: int|null, wear: int|null}>,
     *     selected_choice: string,
     *     manual_level: int,
     *     manual_wear: int,
     *     show_manual: bool
     *   }>,
     *   inputs: array<string, array{choice: string, manual_level: int, manual_wear: int}>,
     *   overrides: array<string, array{level: int, start: int}>
     * }
     */
    public static function parsePartSelections(array $submittedParts, array $partChoices): array
    {
        $inputs = [];
        $overrides = [];

        foreach ($partChoices as $slug => $part) {
            $posted = $submittedParts[$slug] ?? [];
            if (!is_array($posted)) {
                $posted = [];
            }

            $manualLevel = self::clampInt(
                $posted['manual_level'] ?? $part['current']['level'],
                1,
                9,
            );
            $manualWear = self::clampInt(
                $posted['manual_wear'] ?? $part['current']['wear'],
                0,
                100,
            );

            $choice = (string) ($posted['choice'] ?? 'current');
            $selectedChoice = 'current';
            $selectedLevel = $part['current']['level'];
            $selectedWear = $part['current']['wear'];

            if ($choice === 'manual') {
                $selectedChoice = 'manual';
                $selectedLevel = $manualLevel;
                $selectedWear = $manualWear;
            } elseif (preg_match('/^old:(\d+)$/', $choice, $matches) === 1) {
                $oldIndex = (int) $matches[1];
                $old = $part['old_options'][$oldIndex] ?? null;
                if ($old !== null) {
                    $selectedChoice = $old['value'];
                    $selectedLevel = $old['level'];
                    $selectedWear = $old['wear'];
                }
            }

            $part['selected_choice'] = $selectedChoice;
            $part['manual_level'] = $manualLevel;
            $part['manual_wear'] = $manualWear;
            $part['show_manual'] = $selectedChoice === 'manual' || $part['old_options'] === [];
            $partChoices[$slug] = $part;

            $inputs[$slug] = [
                'choice'       => $selectedChoice,
                'manual_level' => $manualLevel,
                'manual_wear'  => $manualWear,
            ];
            $overrides[$part['label']] = [
                'level' => $selectedLevel,
                'start' => $selectedWear,
            ];
        }

        return [
            'choices'   => $partChoices,
            'inputs'    => $inputs,
            'overrides' => $overrides,
        ];
    }

    /**
     * @param array<string, mixed> $option
     */
    private static function isDisabledOption(array $option): bool
    {
        $disabled = $option['disabled'] ?? false;
        if (is_bool($disabled)) {
            return $disabled;
        }
        if (is_numeric($disabled)) {
            return (int) $disabled !== 0;
        }
        if (is_string($disabled)) {
            return in_array(strtolower($disabled), ['1', 'true', 'disabled'], true);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $path
     */
    private static function nestedNumeric(array $row, array $path): ?float
    {
        $value = $row;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return self::numericValue($value);
    }

    private static function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function clampInt(mixed $value, int $min, int $max): int
    {
        $int = is_numeric($value) ? (int) $value : $min;
        return max($min, min($max, $int));
    }
}

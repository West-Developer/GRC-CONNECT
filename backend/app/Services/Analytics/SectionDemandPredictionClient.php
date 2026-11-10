<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use UnexpectedValueException;

/** Laravel-only client for the private prediction-service contract. */
final class SectionDemandPredictionClient
{
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @param  list<array{cohort_size: int, enrolled_count: int, section_count: int, offered_capacity: int, year_level: int, semester: string}>  $observations
     * @param  list<array{key: string, cohort_size: int, section_count: int, recommended_capacity: int, year_level: int, semester: string}>  $targets
     * @return array{model_version: string, feature_schema_version: string, strategy: string, metrics?: array{training_observation_count: int, validation_observation_count: int, mae: float|int|null, rmse: float|int|null}, forecasts: list<array{key: string, predicted_demand: float|int, confidence_lower: float|int, confidence_upper: float|int, suggested_section_count: int}>}
     */
    public function predict(array $observations, array $targets): array
    {
        $payload = [
            'feature_schema_version' => 'v2',
            'observations' => $observations,
            'targets' => $targets,
        ];

        $cacheKey = 'ml:section-demand:v2:'.hash('sha256', (string) json_encode($payload));

        /** @var array{model_version: string, feature_schema_version: string, strategy: string, metrics?: array{training_observation_count: int, validation_observation_count: int, mae: float|int|null, rmse: float|int|null}, forecasts: list<array{key: string, predicted_demand: float|int, confidence_lower: float|int, confidence_upper: float|int, suggested_section_count: int}>} */
        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($payload): array {
            /** @var string $baseUrl */
            $baseUrl = config('services.prediction.base_url');
            /** @var int $timeout */
            $timeout = config('services.prediction.timeout');
            $requestId = (string) (request()->header('X-Request-ID') ?: Str::uuid());

            $data = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->withHeaders(['X-Request-ID' => $requestId])
                ->connectTimeout(2)
                ->timeout($timeout)
                ->retry(2, 200, throw: false)
                ->post('/internal/v1/section-demand/predict', [
                    'data' => $payload,
                ])
                ->throw()
                ->json('data');

            if (! is_array($data)) {
                throw new UnexpectedValueException('Prediction service returned an invalid response.');
            }

            return $data;
        });
    }
}

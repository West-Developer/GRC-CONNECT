<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use UnexpectedValueException;

/** Laravel-only client for the private XGBoost attrition prediction-service contract. */
final class AttritionPredictionClient
{
    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @param  list<array{year_level: int, gpa: float, failed_units: int, dropped_units: int, is_irregular: int, consecutive_terms: int, attrited: int}>  $observations
     * @param  list<array{student_id: int, year_level: int, gpa: float, failed_units: int, dropped_units: int, is_irregular: int, consecutive_terms: int}>  $targets
     * @return array{model_version: string, feature_schema_version: string, strategy: string, metrics?: array{training_observation_count: int, validation_observation_count: int, accuracy: float|null}, predictions: list<array{student_id: int, risk_probability: float, risk_band: string, explanations: list<string>}>}
     */
    public function predict(array $observations, array $targets): array
    {
        $payload = [
            'feature_schema_version' => 'v1',
            'observations' => $observations,
            'targets' => $targets,
        ];

        $cacheKey = 'ml:attrition:v1:'.hash('sha256', (string) json_encode($payload));

        /** @var array{model_version: string, feature_schema_version: string, strategy: string, metrics?: array{training_observation_count: int, validation_observation_count: int, accuracy: float|null}, predictions: list<array{student_id: int, risk_probability: float, risk_band: string, explanations: list<string>}>} */
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
                ->post('/internal/v1/attrition/predict', [
                    'data' => $payload,
                ])
                ->throw()
                ->json('data');

            if (! is_array($data)) {
                throw new UnexpectedValueException('Attrition prediction service returned an invalid response.');
            }

            return $data;
        });
    }
}

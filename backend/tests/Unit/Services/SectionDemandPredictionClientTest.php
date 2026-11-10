<?php

namespace Tests\Unit\Services;

use App\Services\Analytics\SectionDemandPredictionClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SectionDemandPredictionClientTest extends TestCase
{
    public function test_it_sends_aggregate_data_only_to_the_private_prediction_service(): void
    {
        config()->set('services.prediction.base_url', 'http://127.0.0.1:8100');
        Http::fake([
            'http://127.0.0.1:8100/internal/v1/section-demand/predict' => Http::response([
                'data' => [
                    'model_version' => 'section-demand-rf-v2',
                    'feature_schema_version' => 'v2',
                    'strategy' => 'historical_baseline',
                    'forecasts' => [[
                        'key' => 'forecast-row-1',
                        'predicted_demand' => 38.0,
                        'confidence_lower' => 38.0,
                        'confidence_upper' => 38.0,
                        'suggested_section_count' => 1,
                    ]],
                ],
            ]),
        ]);

        $result = app(SectionDemandPredictionClient::class)->predict([
            [
                'cohort_size' => 40,
                'enrolled_count' => 38,
                'section_count' => 1,
                'offered_capacity' => 40,
                'year_level' => 2,
                'semester' => '2nd',
            ],
        ], [
            [
                'key' => 'forecast-row-1',
                'cohort_size' => 42,
                'section_count' => 1,
                'recommended_capacity' => 40,
                'year_level' => 2,
                'semester' => '2nd',
            ],
        ]);

        self::assertSame('historical_baseline', $result['strategy']);
        self::assertEquals(38.0, $result['forecasts'][0]['predicted_demand']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://127.0.0.1:8100/internal/v1/section-demand/predict'
                && $request->method() === 'POST'
                && $request['data']['feature_schema_version'] === 'v2'
                && ! isset($request['data']['student_id']);
        });
    }
}

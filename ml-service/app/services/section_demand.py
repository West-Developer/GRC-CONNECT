from math import ceil

import numpy as np
from sklearn.ensemble import RandomForestRegressor

from app.schemas.section_demand import (
    DemandForecast,
    DemandModelMetrics,
    DemandObservation,
    DemandTarget,
    SectionDemandPredictionData,
)


class SectionDemandPredictor:
    """Fits a deterministic, request-scoped demand model over aggregate data."""

    _MINIMUM_FOREST_OBSERVATIONS = 4

    def predict(
        self,
        observations: list[DemandObservation],
        targets: list[DemandTarget],
        feature_schema_version: str = "v1",
    ) -> SectionDemandPredictionData:
        if feature_schema_version == "v2":
            return self._predict_v2(observations, targets)

        if len(observations) < self._MINIMUM_FOREST_OBSERVATIONS:
            return SectionDemandPredictionData(
                model_version="section-demand-rf-v1",
                feature_schema_version="v1",
                strategy="historical_baseline",
                metrics=self._metrics(len(observations)),
                forecasts=[
                    self._baseline_forecast(observations[-1], target)
                    for target in targets
                ],
            )

        features = [self._observation_features(observation) for observation in observations]
        outcomes = [observation.enrolled_count for observation in observations]
        model = RandomForestRegressor(n_estimators=100, random_state=42)
        model.fit(features, outcomes)

        return SectionDemandPredictionData(
            model_version="section-demand-rf-v1",
            feature_schema_version="v1",
            strategy="random_forest",
            metrics=self._metrics(len(observations)),
            forecasts=[self._forest_forecast(model, target) for target in targets],
        )

    def _predict_v2(
        self,
        observations: list[DemandObservation],
        targets: list[DemandTarget],
    ) -> SectionDemandPredictionData:
        """Predict headcount and block count independently for a cohort.

        Version two replaces v1's capacity-only block calculation with a
        second request-scoped Random Forest trained on the validated historic
        section counts.
        """
        if not observations:
            return SectionDemandPredictionData(
                model_version="section-demand-rf-v2",
                feature_schema_version="v2",
                strategy="historical_baseline",
                metrics=self._metrics(0),
                forecasts=[],
            )

        features = [self._v2_observation_features(observation) for observation in observations]
        demand_model = RandomForestRegressor(n_estimators=100, random_state=42)
        section_model = RandomForestRegressor(n_estimators=100, random_state=42)
        demand_model.fit(features, [observation.enrolled_count for observation in observations])
        section_model.fit(features, [observation.section_count for observation in observations])

        return SectionDemandPredictionData(
            model_version="section-demand-rf-v2",
            feature_schema_version="v2",
            strategy="random_forest",
            metrics=self._metrics(len(observations)),
            forecasts=[
                self._v2_forest_forecast(demand_model, section_model, target)
                for target in targets
            ],
        )

    def _metrics(self, observation_count: int) -> DemandModelMetrics:
        """Avoid publishing accuracy figures when this request lacks a validation set."""
        return DemandModelMetrics(
            training_observation_count=observation_count,
            validation_observation_count=0,
            mae=None,
            rmse=None,
        )

    def _baseline_forecast(
        self, observation: DemandObservation, target: DemandTarget
    ) -> DemandForecast:
        predicted = float(observation.enrolled_count)
        return self._forecast(target, predicted, predicted, predicted)

    def _forest_forecast(
        self, model: RandomForestRegressor, target: DemandTarget
    ) -> DemandForecast:
        vector = np.array([self._target_features(target)])
        tree_predictions = np.array([tree.predict(vector)[0] for tree in model.estimators_])
        predicted = max(0.0, float(tree_predictions.mean()))
        lower = max(0.0, float(np.quantile(tree_predictions, 0.10)))
        upper = max(0.0, float(np.quantile(tree_predictions, 0.90)))
        return self._forecast(target, predicted, min(lower, predicted), max(upper, predicted))

    def _v2_baseline_forecast(
        self, observation: DemandObservation, target: DemandTarget
    ) -> DemandForecast:
        enrollment_rate = observation.enrolled_count / max(observation.cohort_size, 1)
        predicted = max(0.0, float(target.cohort_size) * enrollment_rate)
        return DemandForecast(
            key=target.key,
            predicted_demand=round(predicted, 2),
            confidence_lower=round(predicted, 2),
            confidence_upper=round(predicted, 2),
            suggested_section_count=max(1, observation.section_count),
        )

    def _v2_forest_forecast(
        self,
        demand_model: RandomForestRegressor,
        section_model: RandomForestRegressor,
        target: DemandTarget,
    ) -> DemandForecast:
        vector = np.array([self._v2_target_features(target)])
        demand_predictions = np.array(
            [tree.predict(vector)[0] for tree in demand_model.estimators_]
        )
        section_predictions = np.array(
            [tree.predict(vector)[0] for tree in section_model.estimators_]
        )
        predicted = max(0.0, float(demand_predictions.mean()))
        lower = max(0.0, float(np.quantile(demand_predictions, 0.10)))
        upper = max(0.0, float(np.quantile(demand_predictions, 0.90)))

        return DemandForecast(
            key=target.key,
            predicted_demand=round(predicted, 2),
            confidence_lower=round(min(lower, predicted), 2),
            confidence_upper=round(max(upper, predicted), 2),
            suggested_section_count=max(1, round(float(section_predictions.mean()))),
        )

    def _forecast(
        self, target: DemandTarget, predicted: float, lower: float, upper: float
    ) -> DemandForecast:
        return DemandForecast(
            key=target.key,
            predicted_demand=round(predicted, 2),
            confidence_lower=round(lower, 2),
            confidence_upper=round(upper, 2),
            suggested_section_count=ceil(predicted / target.recommended_capacity),
        )

    def _observation_features(self, observation: DemandObservation) -> list[float]:
        return [
            float(observation.cohort_size),
            float(observation.section_count),
            float(observation.offered_capacity),
            float(observation.year_level),
            1.0 if observation.semester == "2nd" else 0.0,
        ]

    def _target_features(self, target: DemandTarget) -> list[float]:
        return [
            float(target.cohort_size),
            float(target.section_count),
            float(target.recommended_capacity * target.section_count),
            float(target.year_level),
            1.0 if target.semester == "2nd" else 0.0,
        ]

    def _v2_observation_features(self, observation: DemandObservation) -> list[float]:
        return [
            float(observation.cohort_size),
            float(observation.offered_capacity),
            float(observation.year_level),
            1.0 if observation.semester == "2nd" else 0.0,
        ]

    def _v2_target_features(self, target: DemandTarget) -> list[float]:
        return [
            float(target.cohort_size),
            float(target.recommended_capacity * max(target.section_count, 1)),
            float(target.year_level),
            1.0 if target.semester == "2nd" else 0.0,
        ]

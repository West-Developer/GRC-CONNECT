import numpy as np
from xgboost import XGBClassifier

from app.schemas.attrition import (
    AttritionModelMetrics,
    AttritionObservation,
    AttritionPredictionData,
    AttritionTarget,
    StudentAttritionForecast,
)


class AttritionPredictor:
    """Fits an XGBoost classifier over historical student persistence data."""

    _MINIMUM_OBSERVATIONS = 4

    def predict(
        self,
        observations: list[AttritionObservation],
        targets: list[AttritionTarget],
    ) -> AttritionPredictionData:
        classes = {obs.attrited for obs in observations}
        # If too few observations or only single class present, use heuristic baseline
        if len(observations) < self._MINIMUM_OBSERVATIONS or len(classes) < 2:
            return AttritionPredictionData(
                model_version="attrition-xgboost-v1",
                feature_schema_version="v1",
                strategy="heuristic_baseline",
                metrics=AttritionModelMetrics(
                    training_observation_count=len(observations),
                    validation_observation_count=0,
                    accuracy=None,
                ),
                predictions=[
                    self._heuristic_prediction(target) for target in targets
                ],
            )

        features = [self._observation_features(obs) for obs in observations]
        labels = [obs.attrited for obs in observations]

        model = XGBClassifier(
            n_estimators=50,
            max_depth=3,
            learning_rate=0.08,
            random_state=42,
            eval_metric="logloss",
        )
        model.fit(features, labels)

        predictions: list[StudentAttritionForecast] = []
        for target in targets:
            vector = np.array([self._target_features(target)])
            prob = float(model.predict_proba(vector)[0][1])
            prob_bounded = round(min(1.0, max(0.0, prob)), 4)
            band = self._risk_band(prob_bounded)
            explanations = self._generate_explanations(target, prob_bounded)

            predictions.append(
                StudentAttritionForecast(
                    student_id=target.student_id,
                    risk_probability=prob_bounded,
                    risk_band=band,
                    explanations=explanations,
                )
            )

        return AttritionPredictionData(
            model_version="attrition-xgboost-v1",
            feature_schema_version="v1",
            strategy="xgboost",
            metrics=AttritionModelMetrics(
                training_observation_count=len(observations),
                validation_observation_count=0,
                accuracy=None,
            ),
            predictions=predictions,
        )

    def _risk_band(self, probability: float) -> str:
        if probability >= 0.80:
            return "critical"
        if probability >= 0.50:
            return "high"
        if probability >= 0.20:
            return "medium"
        return "low"

    def _generate_explanations(
        self, target: AttritionTarget, probability: float
    ) -> list[str]:
        explanations: list[str] = []
        if target.failed_units >= 6:
            explanations.append(
                f"High academic deficiency with {target.failed_units} accumulated failed units."
            )
        elif target.failed_units > 0:
            explanations.append(
                f"Has {target.failed_units} failed unit(s) requiring remediation."
            )

        if target.dropped_units >= 3:
            explanations.append(
                f"Frequent subject withdrawal ({target.dropped_units} dropped units)."
            )

        if target.gpa >= 3.0:
            explanations.append(f"Low GPA standing ({target.gpa:.2f}).")

        if target.is_irregular == 1:
            explanations.append(
                "Irregular curriculum progression increases stop-out likelihood."
            )

        if target.year_level == 1 and probability >= 0.40:
            explanations.append(
                "First-year transition vulnerability and persistence risk."
            )

        if not explanations:
            explanations.append(
                "Normal academic standing with stable enrollment history."
            )

        return explanations

    def _heuristic_prediction(
        self, target: AttritionTarget
    ) -> StudentAttritionForecast:
        score = 0.05
        score += min(0.40, target.failed_units * 0.06)
        score += min(0.25, target.dropped_units * 0.05)
        if target.gpa >= 3.0:
            score += 0.20
        if target.is_irregular:
            score += 0.10
        if target.year_level == 1:
            score += 0.05

        prob_bounded = round(min(0.95, max(0.02, score)), 4)
        band = self._risk_band(prob_bounded)
        explanations = self._generate_explanations(target, prob_bounded)

        return StudentAttritionForecast(
            student_id=target.student_id,
            risk_probability=prob_bounded,
            risk_band=band,
            explanations=explanations,
        )

    def _observation_features(self, obs: AttritionObservation) -> list[float]:
        return [
            float(obs.year_level),
            float(obs.gpa),
            float(obs.failed_units),
            float(obs.dropped_units),
            float(obs.is_irregular),
            float(obs.consecutive_terms),
        ]

    def _target_features(self, target: AttritionTarget) -> list[float]:
        return [
            float(target.year_level),
            float(target.gpa),
            float(target.failed_units),
            float(target.dropped_units),
            float(target.is_irregular),
            float(target.consecutive_terms),
        ]

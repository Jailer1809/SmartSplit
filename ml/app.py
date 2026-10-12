from __future__ import annotations

import logging
import math
from threading import RLock
from typing import Any

import numpy as np
import pandas as pd
import shap
from flask import Flask, jsonify, request

from blockchain import add_block, all_blocks, verify_chain
from train_model import (
    FEATURE_COLUMNS,
    MODEL_PATH,
    SOURCE_COLUMNS,
    create_features,
    train_model,
)

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("budget-risk-service")
app = Flask(__name__)
model_lock = RLock()
training_result = train_model()
model = training_result["model"]
background = training_result["background"]


def numeric_input(name: str, maximum: float = 99_999_999_999.99) -> float:
    value = request.get_json(silent=True)
    if not isinstance(value, dict):
        raise ValueError("A JSON object is required.")
    raw = value.get(name)
    if isinstance(raw, bool) or not isinstance(raw, (int, float)) or not math.isfinite(raw) or raw < 0 or raw > maximum:
        raise ValueError(f"{name} must be a non-negative number within the supported range.")
    return float(raw)


def explain_low_risk_probability(features: pd.DataFrame) -> dict[str, Any]:
    explainer = shap.TreeExplainer(
        model,
        data=background,
        feature_perturbation="interventional",
        model_output="probability",
    )
    shap_result = explainer.shap_values(features, check_additivity=False)
    classes = list(model.classes_)
    low_index = classes.index("Low")
    if isinstance(shap_result, list):
        impacts = np.asarray(shap_result[low_index])[0]
    else:
        values = np.asarray(shap_result)
        if values.ndim == 3:
            if values.shape[0] == 1 and values.shape[-1] == len(classes):
                impacts = values[0, :, low_index]
            elif values.shape[0] == len(classes) and values.shape[1] == 1:
                impacts = values[low_index, 0, :]
            else:
                raise RuntimeError("SHAP returned an unsupported multiclass explanation shape.")
        elif values.ndim == 2:
            impacts = values[0]
        else:
            raise RuntimeError("SHAP returned an unsupported explanation shape.")
    expected = np.asarray(explainer.expected_value)
    base = float(expected[low_index] if expected.ndim else expected)
    contributions = []
    for name, raw_impact, raw_value in zip(FEATURE_COLUMNS, impacts, features.iloc[0].tolist(), strict=True):
        impact = float(raw_impact)
        contributions.append(
            {
                "factor": name,
                "value": round(float(raw_value), 4),
                "impact": round(impact * 100, 3),
                "direction": "increases" if impact >= 0 else "decreases",
            }
        )
    contributions.sort(key=lambda item: abs(item["impact"]), reverse=True)
    return {
        "method": "SHAP TreeExplainer",
        "explained_output": "Probability of the Low-risk class, in percentage points.",
        "base_value": round(base * 100, 3),
        "factors": contributions,
    }


@app.get("/health")
def health():
    return jsonify({"status": "ok", "model": "DecisionTreeClassifier", "training_rows": training_result["rows"]})


@app.post("/predict")
def predict():
    try:
        values = {name: numeric_input(name) for name in SOURCE_COLUMNS}
        if values["monthly_income"] <= 0:
            raise ValueError("monthly_income must be greater than zero.")
        if values["on_time_payments"] % 1 or values["missed_payments"] % 1:
            raise ValueError("Repayment counts must be whole numbers.")
        if values["on_time_payments"] > 60 or values["missed_payments"] > 60:
            raise ValueError("Repayment counts must not exceed 60.")
        row = pd.DataFrame([values], columns=SOURCE_COLUMNS)
        features = create_features(row)
        with model_lock:
            predicted = str(model.predict(features)[0])
            probabilities = model.predict_proba(features)[0]
            low_index = list(model.classes_).index("Low")
            score = round(float(probabilities[low_index]) * 100, 2)
            explanation = explain_low_risk_probability(features)
        return jsonify(
            {
                "score": score,
                "risk_category": predicted,
                "explanation": explanation,
                "model": "DecisionTreeClassifier",
            }
        )
    except ValueError as error:
        return jsonify({"error": str(error)}), 422
    except Exception:
        logger.exception("Risk prediction failed.")
        return jsonify({"error": "Prediction or SHAP explanation failed. See the ML service log."}), 500


@app.post("/ledger/add")
def ledger_add():
    data = request.get_json(silent=True)
    if not isinstance(data, dict) or not isinstance(data.get("record_data"), dict):
        return jsonify({"error": "record_data must be a JSON object."}), 422
    try:
        block = add_block(
            str(data.get("record_ref_id", "")),
            str(data.get("record_type", "")),
            data["record_data"],
        )
        return jsonify(block), 201
    except ValueError as error:
        return jsonify({"error": str(error)}), 422
    except Exception:
        logger.exception("Audit block append failed.")
        return jsonify({"error": "The audit ledger could not be updated."}), 500


@app.get("/ledger/verify")
def ledger_verify():
    try:
        result = verify_chain()
        result["blocks"] = all_blocks()
        return jsonify(result)
    except Exception:
        logger.exception("Audit chain verification failed.")
        return jsonify({"error": "The audit ledger could not be verified."}), 500


@app.post("/retrain")
def retrain():
    global model, background, training_result
    try:
        result = train_model()
        with model_lock:
            model = result["model"]
            background = result["background"]
            training_result = result
        logger.info("Model retrained using %s synthetic records.", result["rows"])
        return jsonify({"message": "Model retrained from the bundled synthetic dataset.", "rows": result["rows"], "accuracy": result["accuracy"]})
    except Exception:
        logger.exception("Model retraining failed.")
        return jsonify({"error": "Model retraining failed. See the ML service log."}), 500


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5001, debug=False)

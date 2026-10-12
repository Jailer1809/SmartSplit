from __future__ import annotations

from pathlib import Path
from typing import Any

import joblib
import pandas as pd
from sklearn.metrics import accuracy_score, classification_report
from sklearn.model_selection import train_test_split
from sklearn.tree import DecisionTreeClassifier

ROOT = Path(__file__).resolve().parent
DATASET_PATH = ROOT / "data" / "synthetic_finance.csv"
MODEL_PATH = ROOT / "models" / "risk_model.joblib"
LABEL_COLUMN = "risk_category"
SOURCE_COLUMNS = [
    "monthly_income",
    "savings",
    "emi",
    "rent",
    "other_expenses",
    "on_time_payments",
    "missed_payments",
]
FEATURE_COLUMNS = [
    "monthly_income",
    "savings",
    "emi",
    "rent",
    "other_expenses",
    "emi_to_income_ratio",
    "debt_to_income_ratio",
    "savings_to_income_ratio",
    "on_time_payments",
    "missed_payments",
    "repayment_rate",
]
RISK_LABELS = ["High", "Low", "Medium"]


def create_features(frame: pd.DataFrame) -> pd.DataFrame:
    income = frame["monthly_income"].clip(lower=1)
    total_outflow = frame["emi"] + frame["rent"] + frame["other_expenses"] + frame["savings"]
    repayment_total = frame["on_time_payments"] + frame["missed_payments"]
    return pd.DataFrame(
        {
            "monthly_income": frame["monthly_income"],
            "savings": frame["savings"],
            "emi": frame["emi"],
            "rent": frame["rent"],
            "other_expenses": frame["other_expenses"],
            "emi_to_income_ratio": frame["emi"] / income,
            "debt_to_income_ratio": (frame["emi"] + frame["rent"] + frame["other_expenses"]) / income,
            "savings_to_income_ratio": frame["savings"] / income,
            "on_time_payments": frame["on_time_payments"],
            "missed_payments": frame["missed_payments"],
            "repayment_rate": frame["on_time_payments"] / repayment_total.clip(lower=1),
        },
        columns=FEATURE_COLUMNS,
    )


def train_model(dataset_path: Path = DATASET_PATH, model_path: Path = MODEL_PATH) -> dict[str, Any]:
    data = pd.read_csv(dataset_path)
    required = set(SOURCE_COLUMNS + [LABEL_COLUMN])
    missing = required.difference(data.columns)
    if missing:
        raise ValueError(f"Training data is missing required columns: {', '.join(sorted(missing))}")
    if data.empty or data[LABEL_COLUMN].nunique() < 3:
        raise ValueError("Training data must include examples from all three risk categories.")

    features = create_features(data)
    labels = data[LABEL_COLUMN].astype(str)
    x_train, x_test, y_train, y_test = train_test_split(
        features,
        labels,
        test_size=0.2,
        random_state=42,
        stratify=labels,
    )
    model = DecisionTreeClassifier(
        max_depth=6,
        min_samples_leaf=5,
        class_weight="balanced",
        random_state=42,
    )
    model.fit(x_train, y_train)
    predictions = model.predict(x_test)
    model_path.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump({"model": model, "feature_columns": FEATURE_COLUMNS}, model_path)
    return {
        "rows": int(len(data)),
        "accuracy": float(accuracy_score(y_test, predictions)),
        "report": classification_report(y_test, predictions, output_dict=True, zero_division=0),
        "model": model,
        "background": x_train.sample(min(50, len(x_train)), random_state=42),
    }


if __name__ == "__main__":
    result = train_model()
    print(f"Trained on {result['rows']} synthetic records; validation accuracy: {result['accuracy']:.3f}")

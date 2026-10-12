import unittest

from app import app


class RiskApiTests(unittest.TestCase):
    def setUp(self):
        self.client = app.test_client()

    def test_prediction_includes_three_class_score_and_shap_factors(self):
        response = self.client.post(
            "/predict",
            json={
                "monthly_income": 5200,
                "savings": 700,
                "emi": 500,
                "rent": 1100,
                "other_expenses": 600,
                "on_time_payments": 11,
                "missed_payments": 0,
            },
        )

        self.assertEqual(response.status_code, 200)
        result = response.get_json()
        self.assertIn(result["risk_category"], {"Low", "Medium", "High"})
        self.assertGreaterEqual(result["score"], 0)
        self.assertLessEqual(result["score"], 100)
        self.assertEqual(result["explanation"]["method"], "SHAP TreeExplainer")
        self.assertEqual(len(result["explanation"]["factors"]), 11)

    def test_invalid_income_is_rejected(self):
        response = self.client.post(
            "/predict",
            json={
                "monthly_income": 0,
                "savings": 0,
                "emi": 0,
                "rent": 0,
                "other_expenses": 0,
                "on_time_payments": 0,
                "missed_payments": 0,
            },
        )

        self.assertEqual(response.status_code, 422)


if __name__ == "__main__":
    unittest.main()

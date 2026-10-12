import json
import tempfile
import unittest
from pathlib import Path

from blockchain import add_block, verify_chain


class BlockchainTests(unittest.TestCase):
    def test_blocks_link_and_verify(self):
        with tempfile.TemporaryDirectory() as directory:
            ledger = Path(directory) / "ledger.json"
            first = add_block("risk-1", "risk_score", {"score": 72.5}, ledger)
            second = add_block("verify-1", "bank_verification", {"risk_score_id": 1}, ledger)

            self.assertEqual(first["previous_hash"], "0")
            self.assertEqual(second["previous_hash"], first["block_hash"])
            self.assertEqual(verify_chain(ledger)["valid"], True)
            self.assertEqual(verify_chain(ledger)["checked_blocks"], 2)

    def test_tampered_block_is_detected(self):
        with tempfile.TemporaryDirectory() as directory:
            ledger = Path(directory) / "ledger.json"
            add_block("risk-1", "risk_score", {"score": 72.5}, ledger)
            blocks = json.loads(ledger.read_text(encoding="utf-8"))
            blocks[0]["data_hash"] = "0" * 64
            ledger.write_text(json.dumps(blocks), encoding="utf-8")

            result = verify_chain(ledger)
            self.assertFalse(result["valid"])
            self.assertEqual(result["checked_blocks"], 0)


if __name__ == "__main__":
    unittest.main()

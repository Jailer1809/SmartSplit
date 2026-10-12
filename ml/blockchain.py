from __future__ import annotations

import hashlib
import json
import os
import tempfile
import threading
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

LEDGER_PATH = Path(__file__).resolve().parent / "data" / "blockchain.json"
_LOCK = threading.RLock()


def _canonical_json(value: Any) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def _hash(value: Any) -> str:
    return hashlib.sha256(_canonical_json(value).encode("utf-8")).hexdigest()


def _read_chain(path: Path) -> list[dict[str, Any]]:
    if not path.exists():
        return []
    with path.open("r", encoding="utf-8") as source:
        chain = json.load(source)
    if not isinstance(chain, list):
        raise ValueError("The audit ledger file must contain a JSON array.")
    return chain


def _write_chain(path: Path, chain: list[dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(prefix="ledger-", suffix=".json", dir=path.parent)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as target:
            json.dump(chain, target, ensure_ascii=False, separators=(",", ":"))
            target.flush()
            os.fsync(target.fileno())
        os.replace(temporary_name, path)
    except Exception:
        if os.path.exists(temporary_name):
            os.unlink(temporary_name)
        raise


def _calculate_block_hash(block: dict[str, Any]) -> str:
    content = {key: value for key, value in block.items() if key != "block_hash"}
    return _hash(content)


def add_block(
    record_ref_id: str,
    record_type: str,
    record_data: dict[str, Any],
    path: Path = LEDGER_PATH,
) -> dict[str, Any]:
    if not record_ref_id or len(record_ref_id) > 100:
        raise ValueError("record_ref_id must contain 1 to 100 characters.")
    if not record_type or len(record_type) > 40:
        raise ValueError("record_type must contain 1 to 40 characters.")
    if not isinstance(record_data, dict):
        raise ValueError("record_data must be an object.")

    with _LOCK:
        chain = _read_chain(path)
        previous_hash = chain[-1]["block_hash"] if chain else "0"
        block: dict[str, Any] = {
            "block_index": len(chain),
            "timestamp": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "data_hash": _hash(record_data),
            "previous_hash": previous_hash,
            "record_ref_id": record_ref_id,
            "record_type": record_type,
        }
        block["block_hash"] = _calculate_block_hash(block)
        chain.append(block)
        _write_chain(path, chain)
        return block


def verify_chain(path: Path = LEDGER_PATH) -> dict[str, Any]:
    with _LOCK:
        chain = _read_chain(path)
    previous_hash = "0"
    for expected_index, block in enumerate(chain):
        if not isinstance(block, dict):
            return {"valid": False, "checked_blocks": expected_index, "error": "Malformed block."}
        if block.get("block_index") != expected_index:
            return {"valid": False, "checked_blocks": expected_index, "error": "Block index mismatch."}
        if block.get("previous_hash") != previous_hash:
            return {"valid": False, "checked_blocks": expected_index, "error": "Previous hash mismatch."}
        if not isinstance(block.get("block_hash"), str) or block["block_hash"] != _calculate_block_hash(block):
            return {"valid": False, "checked_blocks": expected_index, "error": "Block hash mismatch."}
        previous_hash = block["block_hash"]
    return {"valid": True, "checked_blocks": len(chain), "chain_head": previous_hash}


def all_blocks(path: Path = LEDGER_PATH) -> list[dict[str, Any]]:
    with _LOCK:
        return _read_chain(path)

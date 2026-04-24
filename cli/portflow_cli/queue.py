"""SQLite-backed offline queue for record_link payloads."""

from __future__ import annotations

import json
import sqlite3
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Iterator

from .config import data_dir

SCHEMA = """
CREATE TABLE IF NOT EXISTS records (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    captured_at  TEXT NOT NULL,
    payload      TEXT NOT NULL,
    status       TEXT NOT NULL DEFAULT 'pending'
                  CHECK (status IN ('pending','synced','failed')),
    last_error   TEXT,
    synced_at    TEXT,
    server_link  TEXT
);
CREATE INDEX IF NOT EXISTS idx_records_status ON records(status);
"""


def db_path() -> str:
    return str(data_dir() / "queue.sqlite3")


@contextmanager
def _connect() -> Iterator[sqlite3.Connection]:
    con = sqlite3.connect(db_path())
    con.row_factory = sqlite3.Row
    try:
        con.executescript(SCHEMA)
        yield con
        con.commit()
    finally:
        con.close()


@dataclass
class Record:
    id: int
    captured_at: str
    payload: dict
    status: str
    last_error: str | None
    synced_at: str | None
    server_link: str | None


def _serialize_payload(payload: dict) -> str:
    return json.dumps(payload, ensure_ascii=False, sort_keys=True)


def _row_to_record(row: sqlite3.Row) -> Record:
    return Record(
        id=row["id"],
        captured_at=row["captured_at"],
        payload=json.loads(row["payload"]),
        status=row["status"],
        last_error=row["last_error"],
        synced_at=row["synced_at"],
        server_link=row["server_link"],
    )


def enqueue(payload: dict) -> int:
    """Insert a new pending record. Returns its id."""
    captured_at = payload.get("lldp", {}).get("captured_at") or datetime.now(
        timezone.utc
    ).isoformat(timespec="seconds")
    with _connect() as con:
        cur = con.execute(
            "INSERT INTO records (captured_at, payload) VALUES (?, ?)",
            (captured_at, _serialize_payload(payload)),
        )
        return int(cur.lastrowid)


def get_record(record_id: int) -> Record | None:
    with _connect() as con:
        row = con.execute("SELECT * FROM records WHERE id = ?", (record_id,)).fetchone()
        return _row_to_record(row) if row else None


def update_payload(record_id: int, payload: dict, *, status: str | None = None) -> None:
    captured_at = payload.get("lldp", {}).get("captured_at") or datetime.now(
        timezone.utc
    ).isoformat(timespec="seconds")
    with _connect() as con:
        if status is None:
            con.execute(
                "UPDATE records SET captured_at = ?, payload = ? WHERE id = ?",
                (captured_at, _serialize_payload(payload), record_id),
            )
        else:
            con.execute(
                "UPDATE records SET captured_at = ?, payload = ?, status = ?, last_error = NULL, synced_at = NULL, server_link = NULL WHERE id = ?",
                (captured_at, _serialize_payload(payload), status, record_id),
            )


def reset_record(record_id: int) -> None:
    with _connect() as con:
        con.execute(
            "UPDATE records SET status = 'pending', last_error = NULL, synced_at = NULL, server_link = NULL WHERE id = ?",
            (record_id,),
        )


def delete_record(record_id: int) -> bool:
    with _connect() as con:
        cur = con.execute("DELETE FROM records WHERE id = ?", (record_id,))
        return (cur.rowcount or 0) > 0


def list_records(status: str | None = None) -> list[Record]:
    sql = "SELECT * FROM records"
    params: tuple = ()
    if status:
        sql += " WHERE status = ?"
        params = (status,)
    sql += " ORDER BY id ASC"
    with _connect() as con:
        return [_row_to_record(r) for r in con.execute(sql, params)]


def pending() -> list[Record]:
    return list_records("pending") + list_records("failed")


def mark_synced(record_id: int, server_summary: str) -> None:
    with _connect() as con:
        con.execute(
            "UPDATE records SET status='synced', last_error=NULL,"
            " synced_at=?, server_link=? WHERE id=?",
            (datetime.now(timezone.utc).isoformat(timespec="seconds"), server_summary, record_id),
        )


def mark_failed(record_id: int, error: str) -> None:
    with _connect() as con:
        con.execute(
            "UPDATE records SET status='failed', last_error=? WHERE id=?",
            (error, record_id),
        )


def stats() -> dict[str, int]:
    with _connect() as con:
        out = {"pending": 0, "synced": 0, "failed": 0}
        for r in con.execute("SELECT status, COUNT(*) AS n FROM records GROUP BY status"):
            out[r["status"]] = int(r["n"])
        return out


def purge_synced() -> int:
    with _connect() as con:
        cur = con.execute("DELETE FROM records WHERE status='synced'")
        return cur.rowcount or 0

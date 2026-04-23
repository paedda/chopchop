"""Unit tests for the link API router.

The database session is replaced by an AsyncMock (see conftest.py), so
these tests run without a live database. generate_code is patched where
needed to avoid the codegen touching the session separately.
"""
from datetime import datetime, timedelta, timezone
from unittest.mock import AsyncMock, MagicMock, patch

import pytest
from fastapi.testclient import TestClient


# ── helpers ───────────────────────────────────────────────────────────────────

def make_link(
    code: str = "abc123",
    url: str = "https://example.com",
    expires_at: datetime | None = None,
) -> MagicMock:
    """Build a mock Link object with the attributes the router accesses."""
    link = MagicMock()
    link.id = 1
    link.code = code
    link.url = url
    link.created_at = datetime(2026, 4, 22, 10, 0, 0, tzinfo=timezone.utc)
    link.expires_at = expires_at
    link.clicks = []
    return link


# ── GET /health ───────────────────────────────────────────────────────────────

def test_health_returns_200(client: TestClient) -> None:
    response = client.get("/health")
    assert response.status_code == 200


def test_health_returns_correct_identity(client: TestClient) -> None:
    body = client.get("/health").json()
    assert body["status"] == "ok"
    assert body["language"] == "python"
    assert body["framework"] == "fastapi"


# ── POST /shorten — validation ────────────────────────────────────────────────

def test_shorten_returns_400_for_missing_url(client: TestClient) -> None:
    assert client.post("/shorten", json={}).status_code == 400


def test_shorten_returns_400_for_non_http_url(client: TestClient) -> None:
    assert client.post("/shorten", json={"url": "ftp://example.com"}).status_code == 400


def test_shorten_returns_400_for_invalid_url(client: TestClient) -> None:
    assert client.post("/shorten", json={"url": "not-a-url"}).status_code == 400


def test_shorten_returns_400_for_short_custom_code(client: TestClient) -> None:
    payload = {"url": "https://example.com", "custom_code": "ab"}  # too short
    assert client.post("/shorten", json=payload).status_code == 400


def test_shorten_returns_400_for_custom_code_with_spaces(client: TestClient) -> None:
    payload = {"url": "https://example.com", "custom_code": "bad code"}
    assert client.post("/shorten", json=payload).status_code == 400


def test_shorten_returns_400_for_expires_in_zero(client: TestClient) -> None:
    payload = {"url": "https://example.com", "expires_in": 0}
    assert client.post("/shorten", json=payload).status_code == 400


def test_shorten_returns_400_for_expires_in_too_large(client: TestClient) -> None:
    payload = {"url": "https://example.com", "expires_in": 9_999_999}
    assert client.post("/shorten", json=payload).status_code == 400


def test_shorten_returns_409_for_duplicate_custom_code(
    client: TestClient, db: AsyncMock
) -> None:
    db.scalar.return_value = make_link("taken")  # code already exists
    payload = {"url": "https://example.com", "custom_code": "taken"}
    assert client.post("/shorten", json=payload).status_code == 409


# ── POST /shorten — success ───────────────────────────────────────────────────

def test_shorten_returns_201_with_generated_code(
    client: TestClient, db: AsyncMock
) -> None:
    with patch("app.router.generate_code", return_value="abc123"):
        response = client.post("/shorten", json={"url": "https://example.com/path"})

    assert response.status_code == 201
    body = response.json()
    assert body["code"] == "abc123"
    assert body["url"] == "https://example.com/path"
    assert body["short_url"].endswith("/abc123")
    assert body["expires_at"] is None


def test_shorten_returns_201_with_custom_code(
    client: TestClient, db: AsyncMock
) -> None:
    db.scalar.return_value = None  # code is free
    response = client.post(
        "/shorten", json={"url": "https://example.com", "custom_code": "my-link"}
    )
    assert response.status_code == 201
    assert response.json()["code"] == "my-link"


def test_shorten_sets_expires_at_when_expires_in_given(
    client: TestClient, db: AsyncMock
) -> None:
    with patch("app.router.generate_code", return_value="xyz789"):
        response = client.post(
            "/shorten", json={"url": "https://example.com", "expires_in": 3600}
        )
    assert response.status_code == 201
    assert response.json()["expires_at"] is not None


# ── GET /stats/:code ──────────────────────────────────────────────────────────

def test_stats_returns_404_for_unknown_code(
    client: TestClient, db: AsyncMock
) -> None:
    db.scalar.return_value = None
    assert client.get("/stats/unknown").status_code == 404


def test_stats_returns_200_with_click_count(
    client: TestClient, db: AsyncMock
) -> None:
    db.scalar.return_value = make_link("abc123")
    response = client.get("/stats/abc123")
    assert response.status_code == 200
    body = response.json()
    assert body["code"] == "abc123"
    assert body["total_clicks"] == 0
    assert body["recent_clicks"] == []


# ── GET /:code ────────────────────────────────────────────────────────────────

def test_redirect_returns_404_for_unknown_code(
    client: TestClient, db: AsyncMock
) -> None:
    db.scalar.return_value = None
    assert client.get("/unknown", follow_redirects=False).status_code == 404


def test_redirect_returns_410_for_expired_link(
    client: TestClient, db: AsyncMock
) -> None:
    expired_link = make_link(expires_at=datetime.now(timezone.utc) - timedelta(hours=1))
    db.scalar.return_value = expired_link
    assert client.get("/abc123", follow_redirects=False).status_code == 410


def test_redirect_returns_301_with_location_header(
    client: TestClient, db: AsyncMock
) -> None:
    link = make_link("abc123", "https://example.com/destination")
    db.scalar.return_value = link
    response = client.get("/abc123", follow_redirects=False)
    assert response.status_code == 301
    assert response.headers["location"] == "https://example.com/destination"


def test_redirect_works_for_link_with_future_expiry(
    client: TestClient, db: AsyncMock
) -> None:
    link = make_link(expires_at=datetime.now(timezone.utc) + timedelta(hours=1))
    db.scalar.return_value = link
    response = client.get("/abc123", follow_redirects=False)
    assert response.status_code == 301

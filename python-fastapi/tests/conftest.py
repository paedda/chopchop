"""Shared pytest fixtures for all test modules."""
from unittest.mock import AsyncMock

import pytest
from fastapi.testclient import TestClient

from app.database import get_session
from app.main import app


@pytest.fixture
def db() -> AsyncMock:
    """Provide a mock AsyncSession and wire it into the app's DI graph.

    Tests that need to control what the database returns should configure
    ``db.scalar.return_value`` or ``db.scalar.side_effect`` before making
    requests via the ``client`` fixture.
    """
    session = AsyncMock()

    async def override():
        yield session

    app.dependency_overrides[get_session] = override
    yield session
    app.dependency_overrides.pop(get_session, None)


@pytest.fixture
def client(db: AsyncMock) -> TestClient:
    """HTTP test client with the database session mocked out."""
    with TestClient(app, raise_server_exceptions=False) as c:
        yield c

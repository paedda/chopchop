"""Pydantic request and response schemas."""
from datetime import datetime

from pydantic import BaseModel, ConfigDict


class ShortenRequest(BaseModel):
    """Request body for POST /chop.

    All fields are optional at the schema level; semantic validation
    (URL format, code charset, expires_in range) is done in the route handler
    so we can return 400 instead of Pydantic's default 422.
    """

    url: str | None = None
    custom_code: str | None = None
    expires_in: int | None = None


class ShortenResponse(BaseModel):
    """Response body for a successfully created short link."""

    code: str
    short_url: str
    url: str
    created_at: datetime
    expires_at: datetime | None


class ClickSchema(BaseModel):
    """A single click entry as returned in the stats response."""

    model_config = ConfigDict(from_attributes=True)

    clicked_at: datetime
    referer: str | None
    user_agent: str | None


class StatsResponse(BaseModel):
    """Response body for GET /stats/{code}."""

    model_config = ConfigDict(from_attributes=True)

    code: str
    url: str
    created_at: datetime
    expires_at: datetime | None
    total_clicks: int
    recent_clicks: list[ClickSchema]


class HealthResponse(BaseModel):
    status: str
    language: str
    framework: str

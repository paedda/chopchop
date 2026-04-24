"""Pydantic request and response schemas."""
from datetime import datetime, timezone

from pydantic import BaseModel, ConfigDict, field_serializer


def _fmt_dt(dt: datetime | None) -> str | None:
    """Format a datetime as ISO 8601 with +00:00 offset and no sub-seconds."""
    if dt is None:
        return None
    return dt.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S+00:00")


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

    @field_serializer("created_at", "expires_at")
    def serialize_dt(self, dt: datetime | None) -> str | None:
        return _fmt_dt(dt)


class ClickSchema(BaseModel):
    """A single click entry as returned in the stats response."""

    model_config = ConfigDict(from_attributes=True)

    clicked_at: datetime
    referer: str | None
    user_agent: str | None

    @field_serializer("clicked_at")
    def serialize_dt(self, dt: datetime | None) -> str | None:
        return _fmt_dt(dt)


class StatsResponse(BaseModel):
    """Response body for GET /stats/{code}."""

    model_config = ConfigDict(from_attributes=True)

    code: str
    url: str
    created_at: datetime
    expires_at: datetime | None
    total_clicks: int
    recent_clicks: list[ClickSchema]

    @field_serializer("created_at", "expires_at")
    def serialize_dt(self, dt: datetime | None) -> str | None:
        return _fmt_dt(dt)


class HealthResponse(BaseModel):
    status: str
    language: str
    framework: str

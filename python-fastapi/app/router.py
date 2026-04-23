"""Link API route handlers."""
import re
from datetime import datetime, timedelta, timezone
from urllib.parse import urlparse

from fastapi import APIRouter, Depends, HTTPException, Request
from fastapi.responses import RedirectResponse
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.codegen import generate_code
from app.database import get_session
from app.models import Click, Link
from app.schemas import ClickSchema, HealthResponse, ShortenRequest, ShortenResponse, StatsResponse

router = APIRouter()

_CUSTOM_CODE_RE = re.compile(r"^[a-zA-Z0-9\-]{3,20}$")


def _is_valid_http_url(url: str) -> bool:
    """Return True if *url* is a well-formed HTTP or HTTPS URL with a dotted host."""
    try:
        parsed = urlparse(url)
        host = parsed.hostname or ""
        return parsed.scheme in ("http", "https") and "." in host
    except Exception:
        return False


@router.get("/health", response_model=HealthResponse, summary="Health check")
async def health() -> dict:
    """Return the operational status and identity of this backend."""
    return {"status": "ok", "language": "python", "framework": "fastapi"}


@router.post(
    "/chop",
    response_model=ShortenResponse,
    status_code=201,
    summary="Create a short link",
)
async def chop(
    body: ShortenRequest,
    request: Request,
    session: AsyncSession = Depends(get_session),
) -> ShortenResponse:
    """Chop a URL into a short link, optionally with a custom code and expiry.

    - **url**: valid HTTP or HTTPS URL (required)
    - **custom_code**: 3–20 alphanumeric characters or hyphens (optional)
    - **expires_in**: seconds until the link expires, max 2 592 000 / 30 days (optional)

    Returns 400 for validation failures, 409 if the custom code is already taken.
    """
    if not body.url or not _is_valid_http_url(body.url):
        raise HTTPException(status_code=400, detail="Invalid or missing URL")

    if body.custom_code is not None:
        if not _CUSTOM_CODE_RE.match(body.custom_code):
            raise HTTPException(
                status_code=400,
                detail="custom_code must be 3–20 alphanumeric characters or hyphens",
            )
        existing = await session.scalar(select(Link).where(Link.code == body.custom_code))
        if existing is not None:
            raise HTTPException(status_code=409, detail="Custom code already taken")
        code = body.custom_code
    else:
        code = await generate_code(session)

    expires_at: datetime | None = None
    if body.expires_in is not None:
        if body.expires_in <= 0 or body.expires_in > 2_592_000:
            raise HTTPException(
                status_code=400,
                detail="expires_in must be a positive integer no greater than 2592000",
            )
        expires_at = datetime.now(timezone.utc) + timedelta(seconds=body.expires_in)

    now = datetime.now(timezone.utc)
    link = Link(code=code, url=body.url, created_at=now, expires_at=expires_at)
    session.add(link)
    await session.commit()
    await session.refresh(link)

    base = f"{request.url.scheme}://{request.url.netloc}"
    return ShortenResponse(
        code=link.code,
        short_url=f"{base}/{link.code}",
        url=link.url,
        created_at=link.created_at,
        expires_at=link.expires_at,
    )


@router.get("/stats/{code}", response_model=StatsResponse, summary="Get click statistics")
async def stats(
    code: str,
    session: AsyncSession = Depends(get_session),
) -> StatsResponse:
    """Return click statistics for a short link.

    Clicks are loaded with selectinload() in a single SELECT IN query,
    avoiding the N+1 problem. The response includes the all-time total
    and the 10 most recent clicks, newest first.

    Returns 404 if the code does not exist.
    """
    link = await session.scalar(
        select(Link).options(selectinload(Link.clicks)).where(Link.code == code)
    )
    if link is None:
        raise HTTPException(status_code=404, detail="Link not found")

    recent = sorted(link.clicks, key=lambda c: c.clicked_at, reverse=True)[:10]

    return StatsResponse(
        code=link.code,
        url=link.url,
        created_at=link.created_at,
        expires_at=link.expires_at,
        total_clicks=len(link.clicks),
        recent_clicks=[ClickSchema.model_validate(c) for c in recent],
    )


@router.get("/{code}", summary="Redirect to original URL")
async def redirect_to_url(
    code: str,
    request: Request,
    session: AsyncSession = Depends(get_session),
) -> RedirectResponse:
    """Resolve a short code and issue a 301 redirect to the original URL.

    Records a click (IP address, user-agent, referer) before redirecting.
    The click is persisted before the response so it is never lost even
    if the client drops the connection.

    Returns 404 if the code is unknown, 410 if the link has expired.
    """
    link = await session.scalar(select(Link).where(Link.code == code))
    if link is None:
        raise HTTPException(status_code=404, detail="Link not found")

    if link.expires_at and link.expires_at < datetime.now(timezone.utc):
        raise HTTPException(status_code=410, detail="Link has expired")

    click = Click(
        link_id=link.id,
        clicked_at=datetime.now(timezone.utc),
        ip_address=request.client.host if request.client else None,
        user_agent=request.headers.get("user-agent"),
        referer=request.headers.get("referer"),
    )
    session.add(click)
    await session.commit()

    return RedirectResponse(url=link.url, status_code=301)

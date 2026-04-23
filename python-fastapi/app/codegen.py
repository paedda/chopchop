"""Base62 short code generator."""
import secrets

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import Link

CHARSET = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
CODE_LENGTH = 6
MAX_RETRIES = 3


async def generate_code(session: AsyncSession) -> str:
    """Generate a unique 6-character base62 short code.

    Picks CODE_LENGTH characters at random from CHARSET using secrets.choice(),
    which is backed by the OS CSPRNG (equivalent to PHP's random_int).
    Checks the database for collisions and retries up to MAX_RETRIES times.

    Args:
        session: Active async database session used to check for collisions.

    Returns:
        A unique CODE_LENGTH-character base62 string.

    Raises:
        RuntimeError: If a unique code cannot be found within MAX_RETRIES attempts.
            With 62^6 ≈ 56 billion possible codes this should never happen in practice.
    """
    for _ in range(MAX_RETRIES):
        code = "".join(secrets.choice(CHARSET) for _ in range(CODE_LENGTH))
        existing = await session.scalar(select(Link).where(Link.code == code))
        if existing is None:
            return code

    raise RuntimeError(
        f"Could not generate a unique code after {MAX_RETRIES} attempts"
    )

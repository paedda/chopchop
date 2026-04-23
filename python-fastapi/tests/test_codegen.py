"""Unit tests for the base62 code generator.

All tests use an AsyncMock session so no database is required.
"""
from unittest.mock import AsyncMock

import pytest

from app.codegen import CHARSET, CODE_LENGTH, MAX_RETRIES, generate_code


async def test_generated_code_has_correct_length_and_alphabet():
    """Output must be exactly CODE_LENGTH characters drawn from CHARSET."""
    session = AsyncMock()
    session.scalar.return_value = None  # no collision

    code = await generate_code(session)

    assert len(code) == CODE_LENGTH
    assert all(c in CHARSET for c in code), f"Non-base62 character in '{code}'"


async def test_generated_codes_are_random():
    """50 consecutive calls should produce more than one distinct code.

    The probability of all 50 being identical is 1/62^(6*49) ≈ 0 in practice.
    """
    session = AsyncMock()
    session.scalar.return_value = None

    codes = [await generate_code(session) for _ in range(50)]
    assert len(set(codes)) > 1, "Expected multiple distinct codes across 50 calls"


async def test_retries_once_on_collision():
    """When the first candidate collides, the generator must try again."""
    session = AsyncMock()
    fake_link = object()
    # First call: collision. Second call: free slot.
    session.scalar.side_effect = [fake_link, None]

    code = await generate_code(session)

    assert len(code) == CODE_LENGTH
    assert session.scalar.call_count == 2


async def test_raises_after_max_retries():
    """RuntimeError must be raised when every attempt collides."""
    session = AsyncMock()
    session.scalar.return_value = object()  # always collide

    with pytest.raises(RuntimeError, match="unique code"):
        await generate_code(session)

    assert session.scalar.call_count == MAX_RETRIES

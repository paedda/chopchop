"""SQLAlchemy ORM models mapping to the shared chopchop schema."""
from datetime import datetime

from sqlalchemy import DateTime, ForeignKey, Integer, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.database import Base


class Link(Base):
    """A shortened URL stored in the `links` table.

    expires_at is None when the link has no expiry. The clicks relationship
    uses lazy="raise" to prevent accidental synchronous loads in async
    context — use selectinload() explicitly where clicks are needed.
    """

    __tablename__ = "links"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    code: Mapped[str] = mapped_column(String(10), unique=True, nullable=False)
    url: Mapped[str] = mapped_column(Text, nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    expires_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True), nullable=True)

    clicks: Mapped[list["Click"]] = relationship("Click", back_populates="link", lazy="raise")


class Click(Base):
    """A single visit to a short link, stored in the `clicks` table.

    ip_address is VARCHAR(45) to accommodate both IPv4 and full IPv6 addresses.
    user_agent and referer are unbounded text sourced from request headers.
    """

    __tablename__ = "clicks"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    link_id: Mapped[int] = mapped_column(Integer, ForeignKey("links.id"), nullable=False)
    clicked_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), nullable=False)
    ip_address: Mapped[str | None] = mapped_column(String(45), nullable=True)
    user_agent: Mapped[str | None] = mapped_column(Text, nullable=True)
    referer: Mapped[str | None] = mapped_column(Text, nullable=True)

    link: Mapped["Link"] = relationship("Link", back_populates="clicks")

"""FastAPI application entry point."""
from fastapi import FastAPI, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from app.router import router

app = FastAPI(
    title="ChopChop",
    description="URL shortener — Python/FastAPI implementation",
    version="1.0.0",
)


@app.exception_handler(HTTPException)
async def http_exception_handler(request: Request, exc: HTTPException) -> JSONResponse:
    """Return all HTTP errors as {"error": "..."} to match the shared API contract."""
    return JSONResponse(status_code=exc.status_code, content={"error": exc.detail})


@app.exception_handler(RequestValidationError)
async def validation_exception_handler(
    request: Request, exc: RequestValidationError
) -> JSONResponse:
    """Convert Pydantic validation errors to 400 with the shared error shape."""
    return JSONResponse(status_code=400, content={"error": "Invalid request body"})


app.include_router(router)

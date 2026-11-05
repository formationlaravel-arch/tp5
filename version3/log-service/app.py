import json
import logging
from datetime import datetime, timezone

from fastapi import FastAPI, Request
from pydantic import BaseModel, Field

logging.basicConfig(level=logging.INFO, format="%(message)s")
logger = logging.getLogger("central-audit")
app = FastAPI(title="Central log service")


class Event(BaseModel):
    service: str = Field(min_length=1, max_length=80)
    level: str = Field(default="INFO", max_length=20)
    message: str = Field(min_length=1, max_length=2000)
    request_id: str | None = Field(default=None, max_length=120)


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/events", status_code=202)
async def receive_event(event: Event, request: Request) -> dict[str, str]:
    logger.info(json.dumps({
        "time": datetime.now(timezone.utc).isoformat(),
        "service": event.service,
        "level": event.level.upper(),
        "message": event.message,
        "request_id": event.request_id or request.headers.get("X-Request-ID")
    }, ensure_ascii=False))
    return {"status": "accepted"}

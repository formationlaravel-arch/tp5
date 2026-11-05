import json
import logging
import os
import urllib.error
import urllib.request
from decimal import Decimal

import psycopg
from fastapi import FastAPI, Header, HTTPException, Request
from pydantic import BaseModel, Field

logging.basicConfig(level=logging.INFO, format="%(message)s")
logger = logging.getLogger("inventory-service")
app = FastAPI(title="Inventory service")
DATABASE_URL = os.environ["DATABASE_URL"]
AUTH_URL = os.getenv("AUTH_URL", "http://auth-service:8080")


class ReserveRequest(BaseModel):
    product_id: int = Field(gt=0)
    quantity: int = Field(gt=0, le=20)
    reservation_key: str = Field(min_length=8, max_length=120, pattern=r"^[A-Za-z0-9._-]+$")


class ReleaseRequest(BaseModel):
    reservation_key: str = Field(min_length=8, max_length=120, pattern=r"^[A-Za-z0-9._-]+$")

class RestockRequest(BaseModel):
    product_id: int = Field(gt=0)
    quantity: int = Field(gt=0, le=1000)


def emit(level: str, message: str, request_id: str | None = None) -> None:
    event = {"service": "inventory-service", "level": level, "message": message, "request_id": request_id}
    logger.info(json.dumps(event))
    url = os.getenv("LOG_SERVICE_URL", "")
    if not url:
        return
    request = urllib.request.Request(
        url,
        data=json.dumps(event).encode(),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=1.5):
            pass
    except (urllib.error.URLError, TimeoutError):
        logger.error("Central log service unavailable; event remains in container stdout.")


def authorize(authorization: str | None, request_id: str | None = None) -> dict:
    if not authorization or not authorization.lower().startswith("bearer "):
        raise HTTPException(status_code=401, detail="Bearer token required")
    request = urllib.request.Request(
        AUTH_URL + "/validate",
        headers={"Authorization": authorization, "X-Request-ID": request_id or ""},
        method="GET",
    )
    try:
        with urllib.request.urlopen(request, timeout=2) as response:
            result = json.loads(response.read())
    except urllib.error.HTTPError as error:
        if error.code == 401:
            raise HTTPException(status_code=401, detail="Invalid or expired token") from error
        raise HTTPException(status_code=503, detail="Authentication service unavailable") from error
    except (urllib.error.URLError, TimeoutError) as error:
        raise HTTPException(status_code=503, detail="Authentication service unavailable") from error
    if not result.get("valid") or not result.get("role"):
        raise HTTPException(status_code=401, detail="Invalid or expired token")
    return result


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "service": "inventory-service"}

@app.get("/products")
def products(
    request: Request,
    authorization: str | None = Header(default=None),
) -> dict:
    authorize(authorization, request.headers.get("X-Request-ID"))
    with psycopg.connect(DATABASE_URL) as connection:
        rows = connection.execute(
            "SELECT id, name, unit_price, stock FROM products ORDER BY id"
        ).fetchall()
    return {"data": [
        {"id": row[0], "name": row[1], "price": str(row[2]), "stock": row[3]}
        for row in rows
    ]}


@app.post("/restock")
def restock(
    payload: RestockRequest,
    request: Request,
    authorization: str | None = Header(default=None),
) -> dict:
    identity = authorize(authorization, request.headers.get("X-Request-ID"))
    if identity.get("role") not in {"INVENTORY_MANAGER", "ADMIN"}:
        raise HTTPException(status_code=403, detail="INVENTORY_MANAGER or ADMIN role required")
    with psycopg.connect(DATABASE_URL) as connection:
        row = connection.execute(
            "UPDATE products SET stock = stock + %s WHERE id = %s RETURNING id, name, unit_price, stock",
            (payload.quantity, payload.product_id),
        ).fetchone()
    if not row:
        raise HTTPException(status_code=404, detail="Product not found")
    emit("INFO", f"Restocked product {payload.product_id} by {payload.quantity}", request.headers.get("X-Request-ID"))
    return {"data": {"id": row[0], "name": row[1], "price": str(row[2]), "stock": row[3]}}


@app.post("/reserve")
def reserve(
    payload: ReserveRequest,
    request: Request,
    authorization: str | None = Header(default=None),
) -> dict:
    identity = authorize(authorization, request.headers.get("X-Request-ID"))
    if identity.get("role") not in {"CUSTOMER", "ADMIN"}:
        raise HTTPException(status_code=403, detail="CUSTOMER or ADMIN role required")
    with psycopg.connect(DATABASE_URL) as connection:
        with connection.transaction():
            existing = connection.execute(
                "SELECT product_id, quantity, total, state FROM reservations WHERE reservation_key = %s FOR UPDATE",
                (payload.reservation_key,),
            ).fetchone()
            if existing:
                if existing[3] != "RESERVED" or existing[0] != payload.product_id or existing[1] != payload.quantity:
                    raise HTTPException(status_code=409, detail="Reservation key already used")
                return {"reservation_key": payload.reservation_key, "product_id": existing[0],
                        "quantity": existing[1], "total": str(existing[2])}
            product = connection.execute(
                "UPDATE products SET stock = stock - %s WHERE id = %s AND stock >= %s RETURNING name, unit_price",
                (payload.quantity, payload.product_id, payload.quantity),
            ).fetchone()
            if not product:
                raise HTTPException(status_code=409, detail="Product not found or insufficient stock")
            total = (product[1] * payload.quantity).quantize(Decimal("0.01"))
            connection.execute(
                "INSERT INTO reservations (reservation_key, product_id, quantity, total, state) VALUES (%s, %s, %s, %s, 'RESERVED')",
                (payload.reservation_key, payload.product_id, payload.quantity, total),
            )
    emit("INFO", "Stock reserved for " + payload.reservation_key, request.headers.get("X-Request-ID"))
    return {"reservation_key": payload.reservation_key, "product_name": product[0],
            "product_id": payload.product_id, "quantity": payload.quantity, "total": str(total)}


@app.post("/release")
def release(
    payload: ReleaseRequest,
    request: Request,
    authorization: str | None = Header(default=None),
) -> dict[str, str]:
    identity = authorize(authorization, request.headers.get("X-Request-ID"))
    if identity.get("role") not in {"CUSTOMER", "ADMIN"}:
        raise HTTPException(status_code=403, detail="CUSTOMER or ADMIN role required")
    with psycopg.connect(DATABASE_URL) as connection:
        with connection.transaction():
            reservation = connection.execute(
                "SELECT product_id, quantity, state FROM reservations WHERE reservation_key = %s FOR UPDATE",
                (payload.reservation_key,),
            ).fetchone()
            if not reservation:
                raise HTTPException(status_code=404, detail="Reservation not found")
            if reservation[2] == "RESERVED":
                connection.execute(
                    "UPDATE products SET stock = stock + %s WHERE id = %s",
                    (reservation[1], reservation[0]),
                )
                connection.execute(
                    "UPDATE reservations SET state = 'RELEASED' WHERE reservation_key = %s",
                    (payload.reservation_key,),
                )
    emit("INFO", "Stock released for " + payload.reservation_key, request.headers.get("X-Request-ID"))
    return {"status": "released"}

"""Additive, read-only router. Import and include in the EXISTING FastAPI app.

This private endpoint is NOT an approval authority. Only the PHP backend is allowed
 to assemble the current DB snapshot. No DB writes or schedule persistence.
"""
import hmac
import os
from fastapi import APIRouter, Body, Header, HTTPException
from app.special_class_solver import solve_special_class

router = APIRouter()

@router.post('/api/special-classes/preview')
def preview_special_class(payload: dict = Body(...), x_bcp_internal_key: str | None = Header(default=None)):
    expected = os.environ.get('BCP_INTERNAL_SCHEDULER_KEY', '')
    if not expected or len(expected) < 32 or not x_bcp_internal_key or not hmac.compare_digest(expected, x_bcp_internal_key):
        raise HTTPException(status_code=403, detail='Private scheduler endpoint is not configured or unauthorized.')
    return solve_special_class(payload)

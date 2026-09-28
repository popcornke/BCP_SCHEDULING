"""Separate LOCAL-ONLY Phase 6F demo process. Run on 127.0.0.1:8001.
Does NOT attach to the official main.py app, change policies, or write to MySQL.
Enable explicitly using BCP_SPECIAL_DEMO_RUN=1; do NOT deploy this server.
"""
import os
from fastapi import FastAPI, Body, HTTPException, Request
from app.special_class_demo_solver import solve_demo_special_class

app = FastAPI(title='BCP Phase 6F isolated DEMO test service', docs_url=None, redoc_url=None,
              openapi_url=None)


def local_only(request: Request) -> None:
    if os.environ.get('BCP_SPECIAL_DEMO_RUN') != '1' or not request.client or request.client.host not in ('127.0.0.1', '::1'):
        raise HTTPException(status_code=403, detail='This isolated DEMO process must be explicitly enabled and accessed via loopback.')


@app.get('/demo/health')
def demo_health(request: Request):
    local_only(request)
    return {'success': True, 'status': 'ISOLATED_DEMO_SERVICE_READY', 'demo_only': True,
            'official_policy_approved': False, 'saving_enabled': False, 'database_write': False}


@app.post('/demo/weekly-preview')
def demo_weekly_preview(request: Request, payload: dict = Body(...)):
    local_only(request)
    policy = payload.get('policy')
    if not isinstance(policy, dict) or policy.get('demo_test_mode') is not True or policy.get('policy_source') != 'ISOLATED_DEMO_SIMULATION':
        raise HTTPException(status_code=422, detail='Isolated DEMO simulation source required.')
    # Reject a payload attempting to represent an actual official approval.
    if policy.get('duration_verified_from_database') is True or policy.get('approval_reference'):
        raise HTTPException(status_code=422, detail='Official policy claims are not accepted in DEMO mode.')
    result = solve_demo_special_class(payload)
    return dict(result, demo_only=True, official_policy_approved=False, database_write=False,
                saving_enabled=False)

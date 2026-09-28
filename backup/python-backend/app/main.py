from fastapi import FastAPI, Body, HTTPException
from ortools.sat.python import cp_model

from app.scheduler import solve_schedule
from app.conflict_checker import audit_schedule

import logging


app = FastAPI(
    title="BCP Scheduling Optimization API",
    version="1.0.0",
    description=(
        "Optimization backend for the "
        "BCP Automatic Class Scheduling System."
    ),
)


# ============================================
# ROOT ENDPOINT
# ============================================

@app.get("/")
def root():
    return {
        "success": True,
        "service": "BCP Scheduling API",
        "message": "Python backend is running.",
    }


# ============================================
# HEALTH CHECK
# ============================================

@app.get("/api/health")
def health_check():

    model = cp_model.CpModel()

    model.new_bool_var("health_check_variable")

    return {
        "success": True,
        "status": "healthy",
        "backend": "Python",
        "api": "FastAPI",
        "optimizer": "Google OR-Tools CP-SAT",
        "optimizer_import": "successful",
        "solver_ready": True,
        "message": (
            "Python API and scheduling "
            "preview endpoint are available."
        ),
    }


# ============================================
# GENERATE TIMETABLE PREVIEW
# ============================================

@app.post("/api/schedules/preview")
def generate_schedule_preview(
    payload: dict = Body(...)
):

    try:

        result = solve_schedule(payload)
        if not result.get("success"):
            return result
        audit = audit_schedule(payload, result)
        result["audit"] = audit
        result["school_wide_validation_complete"] = False
        result["database_write"] = False
        if not audit["passed"]:
            result["success"] = False
            result["status"] = "SCHEDULE_CONFLICTS_DETECTED"
            result["message"] = "Independent timetable audit failed; preview is not approved."
            result["unapproved_meetings_count"] = len(result.get("assignments", []))
            result["assignments"] = []
            result["returned_meetings"] = 0
        return result

    except ValueError as error:

        raise HTTPException(
            status_code=422,
            detail=str(error),
        )

    except Exception:

        logging.exception(
            "Scheduling optimization failed."
        )

        raise HTTPException(
            status_code=500,
            detail=(
                "Scheduling optimization failed. "
                "Check the Python backend logs."
            ),
        )
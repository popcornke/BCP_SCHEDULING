from concurrent.futures import ThreadPoolExecutor
from threading import Lock
from uuid import uuid4
import logging

from fastapi import FastAPI, Body, HTTPException
from ortools.sat.python import cp_model

from app.scheduler import solve_schedule, MAX_SOLVE_SECONDS
from app.conflict_checker import audit_schedule
from app.exam_routes import router as exam_router


app = FastAPI(
    title="BCP Scheduling Optimization API",
    version="1.0.0",
    description=(
        "Optimization backend for the "
        "BCP Automatic Class Scheduling System."
    ),
)


# ============================================
# BACKGROUND SCHEDULING JOBS
# ============================================

schedule_jobs = {}
schedule_jobs_lock = Lock()

# One scheduling job at a time.
# The OR-Tools solver itself already uses
# multiple search workers.
schedule_executor = ThreadPoolExecutor(
    max_workers=1
)


def solve_and_audit(payload: dict) -> dict:
    """
    Run the exact scheduling + independent
    audit flow used by the preview endpoint.
    """

    result = solve_schedule(payload)

    if not result.get("success"):
        return result

    audit = audit_schedule(
        payload,
        result
    )

    result["audit"] = audit
    result[
        "school_wide_validation_complete"
    ] = False
    result["database_write"] = False

    if not audit["passed"]:

        result["success"] = False
        result[
            "status"
        ] = "SCHEDULE_CONFLICTS_DETECTED"

        result["message"] = (
            "Independent timetable audit failed; "
            "preview is not approved."
        )

        result[
            "unapproved_meetings_count"
        ] = len(
            result.get(
                "assignments",
                [],
            )
        )

        result["assignments"] = []
        result["returned_meetings"] = 0

    return result


def run_schedule_job(
    job_id: str,
    payload: dict,
) -> None:
    """
    Execute a scheduling request outside
    the original HTTP request.
    """

    with schedule_jobs_lock:

        if job_id not in schedule_jobs:
            return

        schedule_jobs[job_id][
            "job_status"
        ] = "RUNNING"

    try:

        result = solve_and_audit(
            payload
        )

        with schedule_jobs_lock:

            schedule_jobs[job_id].update({
                "job_status": "COMPLETED",
                "result": result,
            })

    except Exception as error:

        logging.exception(
            "Background scheduling job failed."
        )

        with schedule_jobs_lock:

            schedule_jobs[job_id].update({
                "job_status": "FAILED",
                "error": str(error),
            })


# ============================================
# MODULE 4 — EXAM TIMETABLE GENERATOR
# ============================================

app.include_router(
    exam_router
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

    model.new_bool_var(
        "health_check_variable"
    )

    return {
        "success": True,
        "status": "healthy",
        "backend": "Python",
        "api": "FastAPI",
        "optimizer": "Google OR-Tools CP-SAT",
        "optimizer_import": "successful",
        "solver_ready": True,
        "max_solve_seconds": MAX_SOLVE_SECONDS,
        "message": (
            "Python API and scheduling "
            "preview endpoint are available."
        ),
    }
# ============================================
# SYNCHRONOUS PREVIEW
#
# Keep this endpoint for direct diagnostics.
# The deployed PHP UI will use jobs instead.
# ============================================

@app.post("/api/schedules/preview")
def generate_schedule_preview(
    payload: dict = Body(...)
):

    try:

        return solve_and_audit(
            payload
        )

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


# ============================================
# CREATE BACKGROUND SCHEDULING JOB
# ============================================

@app.post("/api/schedules/jobs")
def create_schedule_job(
    payload: dict = Body(...)
):

    job_id = str(
        uuid4()
    )

    with schedule_jobs_lock:

        schedule_jobs[job_id] = {
            "job_id": job_id,
            "job_status": "QUEUED",
            "result": None,
            "error": None,
        }

    schedule_executor.submit(
        run_schedule_job,
        job_id,
        payload,
    )

    return {
        "success": True,
        "status": "SCHEDULE_JOB_QUEUED",
        "job_id": job_id,
        "job_status": "QUEUED",
        "database_write": False,
    }


# ============================================
# GET BACKGROUND JOB STATUS
# ============================================

@app.get("/api/schedules/jobs/{job_id}")
def get_schedule_job(
    job_id: str
):

    with schedule_jobs_lock:

        job = schedule_jobs.get(
            job_id
        )

        if job is None:

            raise HTTPException(
                status_code=404,
                detail="Scheduling job was not found.",
            )

        response = {
            "success": True,
            "status": "SCHEDULE_JOB_STATUS",
            "job_id": job_id,
            "job_status": job[
                "job_status"
            ],
        }

        if (
            job["job_status"]
            == "COMPLETED"
        ):

            response["result"] = job[
                "result"
            ]

        elif (
            job["job_status"]
            == "FAILED"
        ):

            response["success"] = False
            response["error"] = (
                job.get("error")
                or "Scheduling job failed."
            )

        return response


# ============================================
# AUDIT EXISTING PREVIEW
# ============================================

@app.post("/api/schedules/audit")
def audit_existing_preview(
    payload: dict = Body(...)
):

    try:

        source = payload["input"]
        result = payload["result"]

        if (
            not isinstance(source, dict)
            or not isinstance(result, dict)
        ):

            raise ValueError(
                "Invalid audit payload"
            )

        return audit_schedule(
            source,
            result,
        )

    except (
        KeyError,
        TypeError,
        ValueError,
    ) as error:

        raise HTTPException(
            status_code=422,
            detail=str(error),
        )

    except Exception:

        logging.exception(
            "Independent preview audit failed"
        )

        raise HTTPException(
            status_code=500,
            detail="Independent audit unavailable",
        )
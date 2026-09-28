"""Add this independent router to the existing FastAPI app; do not replace main.py."""
from fastapi import APIRouter, Body
from app.exam_solver import solve_exam

router = APIRouter()

@router.post('/api/exams/preview')
def preview_exams(payload: dict = Body(...)):
    return solve_exam(payload)

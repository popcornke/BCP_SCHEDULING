import sys, unittest, copy
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'python-backend'))
from app.special_class_audit import audit_special_class
from app.special_class_solver import solve_special_class


def facts():
    f = {'policy': {'approved_duration_minutes': 60, 'approval_reference':'TEST ONLY', 'duration_verified_from_database':True},
         'request': {'class_type':'REMEDIAL', 'recurrence':'WEEKLY', 'teacher_id':1,
                     'program_id':4, 'teacher_authorized':True, 'delivery_mode':'F2F',
                     'max_daily_hours':8, 'max_weekly_hours':30,
                     'participants':[{'student_id':10,'home_section_id':100,'major_section_id':200}]},
         'weekly_occurrences':[{'date':'2026-10-05','day_of_week':'Monday'}, {'date':'2026-10-12','day_of_week':'Monday'}],
         'time_slots':[], 'rooms':[{'room_id':1,'program_id':4,'status':'AVAILABLE','capacity':50}],
         'room_availability':[{'room_id':1,'day_of_week':'Monday','availability_status':'AVAILABLE','start_time':'06:00','end_time':'21:00'}],
         'teacher_availability':[{'teacher_id':1,'day_of_week':'Monday','availability_status':'AVAILABLE','start_time':'06:00','end_time':'21:00'}],
         'existing_classes':[], 'existing_exams':[], 'existing_substitutions':[], 'existing_special_classes':[]}
    for h in range(6,21):
        for m in (0,30):
            n=h*60+m
            f['time_slots'].append({'day_of_week':'Monday','is_active':1,'start_time':f'{n//60:02d}:{n%60:02d}', 'end_time':f'{(n+30)//60:02d}:{(n+30)%60:02d}'})
    return f


def meetings():
    return [{'meeting_date':d,'start_time':'08:00','end_time':'09:00', 'teacher_id':1,'room_id':1,'delivery_mode':'F2F'}
            for d in ('2026-10-05','2026-10-12')]


class IndependentAudit(unittest.TestCase):
    def test_clean_schedule(self):
        self.assertTrue(audit_special_class(facts(), meetings())['passed'])
    def test_duration_gate(self):
        f=facts(); f['policy']['approved_duration_minutes']=None
        self.assertFalse(audit_special_class(f,meetings())['passed'])
        self.assertEqual(solve_special_class(f)['status'],'DURATION_APPROVAL_REQUIRED')
    def test_major_student_overlap(self):
        f=facts(); f['existing_classes']=[{'meeting_id':77,'section_id':200,'teacher_id':7,'room_id':7,'day_of_week':'Monday','start_time':'08:30','end_time':'09:30'}]
        self.assertIn('REGULAR_STUDENT_CONFLICT', [x['code'] for x in audit_special_class(f, meetings())['issues']])
    def test_teacher_overlap(self):
        f=facts(); f['existing_classes']=[{'meeting_id':77,'section_id':300,'teacher_id':1,'room_id':7,'day_of_week':'Monday','start_time':'08:30','end_time':'09:30'}]
        self.assertIn('REGULAR_TEACHER_CONFLICT', [x['code'] for x in audit_special_class(f, meetings())['issues']])
    def test_room_overlap(self):
        f=facts(); f['existing_classes']=[{'meeting_id':77,'section_id':300,'teacher_id':7,'room_id':1,'day_of_week':'Monday','start_time':'08:30','end_time':'09:30'}]
        self.assertIn('REGULAR_ROOM_CONFLICT', [x['code'] for x in audit_special_class(f, meetings())['issues']])
    def test_exam_major_student(self):
        f=facts(); f['existing_exams']=[{'exam_date':'2026-10-05','section_id':200,'proctor_id':4,'room_id':4,'start_time':'08:00','end_time':'09:00'}]
        self.assertIn('EXAM_STUDENT_CONFLICT', [x['code'] for x in audit_special_class(f, meetings())['issues']])
    def test_substitute_duty(self):
        f=facts(); f['existing_substitutions']=[{'duty_date':'2026-10-05','substitute_teacher_id':1,'start_time':'08:00','end_time':'09:00'}]
        self.assertIn('SUBSTITUTE_DUTY_CONFLICT', [x['code'] for x in audit_special_class(f, meetings())['issues']])
    def test_special_student(self):
        f=facts(); f['existing_special_classes']=[{'meeting_date':'2026-10-05','teacher_id':2,'room_id':2,'start_time':'08:00','end_time':'09:00','participant_student_ids':[10]}]
        self.assertIn('SPECIAL_STUDENT_CONFLICT', [x['code'] for x in audit_special_class(f, meetings())['issues']])
    def test_missing_db_slot(self):
        f=facts(); f['time_slots']=[s for s in f['time_slots'] if s['start_time']!='08:30']
        self.assertIn('DATABASE_SLOT_MISSING', [x['code'] for x in audit_special_class(f, meetings())['issues']])
    def test_weekly_time_change(self):
        m=meetings();m[1]['start_time']='09:00';m[1]['end_time']='10:00'
        self.assertIn('INCONSISTENT_WEEKLY_TIME', [x['code'] for x in audit_special_class(facts(),m)['issues']])
    def test_online_requires_no_room(self):
        m=meetings();m[0]['delivery_mode']='ONLINE'
        self.assertIn('ROOM_MODE_MISMATCH',[x['code'] for x in audit_special_class(facts(),m)['issues']])
    def test_octoberian_policy_gate(self):
        f=facts();f['request']['class_type']='OCTOBERIAN'
        self.assertEqual(solve_special_class(f)['status'],'OCTOBERIAN_POLICY_PENDING')

if __name__=='__main__': unittest.main(verbosity=2)

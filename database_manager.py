import mysql.connector
import numpy as np
from datetime import datetime, timedelta

class DatabaseManager:
    def __init__(self):
        try:
            self.db = mysql.connector.connect(
                host="localhost",
                user="root",
                password="paganini019",
                database="face_recognition_db",
                port=3308
            )
            self.cursor = self.db.cursor()
        except mysql.connector.Error as err:
            print(f"Error: {err}")
            print(f"Error Code: {err.errno}")
            print(f"SQLSTATE: {err.sqlstate}")
            raise
    
    def load_known_faces(self):
        known_face_encodings = []
        known_face_names = []
        known_face_ids = []
        self.cursor.execute("SELECT id, name, face_encoding FROM users")
        
        for (id, name, face_encoding) in self.cursor:
            expected_size = 1024  # Assuming 128 values with np.float64 (8 bytes per float)
            actual_size = len(face_encoding)
            
            if actual_size != expected_size:
                print(f"Error: Face encoding for user {name} has incorrect length ({actual_size} bytes). Expected {expected_size} bytes.")
                # You may decide to skip this entry, attempt re-encoding, or flag it for further investigation
                continue
            
            known_face_ids.append(id)
            known_face_names.append(name)
            known_face_encodings.append(np.frombuffer(face_encoding, dtype=np.float64))
        
        return known_face_encodings, known_face_names, known_face_ids


    def get_current_schedule(self, user_id):
        current_time = datetime.now().time()
        day_of_week = datetime.now().strftime('%A')
        
        query = """
        SELECT s.schedule_id, s.start_time, s.end_time, c.course_name
        FROM schedules s
        JOIN courses c ON s.course_id = c.course_id
        WHERE s.user_id = %s
        AND s.day_of_week = %s
        AND %s BETWEEN s.start_time AND s.end_time
        """
        self.cursor.execute(query, (user_id, day_of_week, current_time))
        return self.cursor.fetchone()

    def log_attendance(self, user_id, schedule_id):
        print(f"Logging attendance for user {user_id}, schedule {schedule_id}")
        current_time = datetime.now()
        current_time_only = current_time.time()
        last_log_time = self.get_last_log_time(user_id, schedule_id)

        print(f"Last log time: {last_log_time}")
        
        # Check if it's too soon to log again
        if last_log_time and (current_time - last_log_time) < timedelta(minutes=30):
            print("Too soon to log again")
            return "Too soon to log again"

        schedule = self.get_schedule_by_id(schedule_id)
        print(f"Schedule: {schedule}")

        # Convert timedelta to time for comparison
        start_time = (datetime.min + schedule['start_time']).time()
        end_time = (datetime.min + schedule['end_time']).time()

        # Determine status based on arrival time
        if current_time_only < start_time:
            status = 'Present'  # Arrived early
        elif current_time_only <= end_time:
            status = 'Present'  # On time
        else:
            status = 'Left early'

        print(f"Status: {status}")

        query = "INSERT INTO attendance (user_id, schedule_id, scan_time, status) VALUES (%s, %s, %s, %s)"

        try:
            self.cursor.execute(query, (user_id, schedule_id, current_time, status))
            print("Query executed successfully")
            self.db.commit()
            print("Commit successful: Attendance logged")
        except mysql.connector.Error as err:
            print(f"Error logging attendance: {err}")
            self.db.rollback()

        
        return status

    def get_last_log_time(self, user_id, schedule_id):
        query = """
        SELECT MAX(scan_time) FROM attendance 
        WHERE user_id = %s AND schedule_id = %s AND DATE(scan_time) = CURDATE()
        """
        self.cursor.execute(query, (user_id, schedule_id))
        result = self.cursor.fetchone()
        return result[0] if result else None

    def get_user_name(self, user_id):
        query = "SELECT name FROM users WHERE id = %s"
        self.cursor.execute(query, (user_id,))
        result = self.cursor.fetchone()
        return result[0] if result else None

    def get_schedule_by_id(self, schedule_id):
        query = "SELECT * FROM schedules WHERE schedule_id = %s"
        self.cursor.execute(query, (schedule_id,))
        row = self.cursor.fetchone()
        if row:
            columns = [column[0] for column in self.cursor.description]
            result = dict(zip(columns, row))
            # Convert start_time and end_time to timedelta
            if isinstance(result['start_time'], str):
                hours, minutes, seconds = map(int, result['start_time'].split(':'))
                result['start_time'] = timedelta(hours=hours, minutes=minutes, seconds=seconds)
            if isinstance(result['end_time'], str):
                hours, minutes, seconds = map(int, result['end_time'].split(':'))
                result['end_time'] = timedelta(hours=hours, minutes=minutes, seconds=seconds)
            return result
        return None
    
    def get_today_scanned_students(self):
        """
        Retrieve a sorted list of students who scanned today, ordered by scan time.
        """
        query = """
        SELECT u.name, a.scan_time
        FROM attendance a
        JOIN users u ON a.user_id = u.id
        WHERE DATE(a.scan_time) = CURDATE()
        ORDER BY a.scan_time ASC
        """
        
        try:
            self.cursor.execute(query)
            results = self.cursor.fetchall()
            # Each entry in results will be a tuple: (name, scan_time)
            scanned_students = [{"name": name, "scan_time": scan_time} for (name, scan_time) in results]
            return scanned_students
        except mysql.connector.Error as err:
            print(f"Error retrieving scanned students: {err}")
            return []

    def close(self):
        self.db.close()

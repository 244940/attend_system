import mysql.connector
import numpy as np
from datetime import datetime, timedelta
import logging

# Setup logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

class DatabaseManager:
    def __init__(self):
        try:
            self.db = mysql.connector.connect(
                host="localhost",
                user="root",
                password="paganini019",
                database="attend_data",
                port=3308
            )
            self.cursor = self.db.cursor()
            logging.info("Connected to attend_data database")
        except mysql.connector.Error as err:
            logging.error(f"Database connection error: {err}, Code: {err.errno}, SQLSTATE: {err.sqlstate}")
            raise

    def load_known_faces(self):
        """
        Load face encodings from students table (assumes face_encoding is a BLOB).
        """
        known_face_encodings = []
        known_face_names = []
        known_face_ids = []
        try:
            self.cursor.execute("SELECT student_id, name, face_encoding FROM students WHERE face_encoding IS NOT NULL")
            for (student_id, name, face_encoding) in self.cursor:
                if face_encoding:
                    expected_size = 1024  # 128 floats * 8 bytes (np.float64)
                    actual_size = len(face_encoding)
                    if actual_size != expected_size:
                        logging.warning(f"Invalid face encoding for student {name} (ID: {student_id}): {actual_size} bytes, expected {expected_size}")
                        continue
                    known_face_ids.append(student_id)
                    known_face_names.append(name)
                    known_face_encodings.append(np.frombuffer(face_encoding, dtype=np.float64))
            logging.info("Loaded %d known faces", len(known_face_encodings))
        except mysql.connector.Error as err:
            logging.error(f"Error loading known faces: {err}")
        return known_face_encodings, known_face_names, known_face_ids

    def get_current_schedule(self, student_id, course_id, schedule_id):
        """
        Get schedule for a student based on course_id, schedule_id, and current day.
        """
        try:
            day_of_week = datetime.now().strftime('%A')
            query = """
            SELECT s.schedule_id, s.start_time, s.end_time, c.course_name
            FROM schedules s
            JOIN courses c ON s.course_id = c.course_id
            JOIN enrollments e ON c.course_id = e.course_id
            WHERE e.student_id = %s AND c.course_id = %s AND s.schedule_id = %s
            AND s.day_of_week = %s
            """
            self.cursor.execute(query, (student_id, course_id, schedule_id, day_of_week))
            result = self.cursor.fetchone()
            if result:
                logging.info("Found schedule: student_id=%s, course_id=%s, schedule_id=%s", student_id, course_id, schedule_id)
                return result
            logging.warning("No schedule found: student_id=%s, course_id=%s, schedule_id=%s", student_id, course_id, schedule_id)
            return None
        except mysql.connector.Error as err:
            logging.error(f"Error in get_current_schedule: {err}")
            return None

    def log_attendance(self, student_id, schedule_id):
        """
        Log attendance with status based on schedule time.
        """
        try:
            # Check for recent logs (within 30 minutes)
            last_log_time = self.get_last_log_time(student_id, schedule_id)
            if last_log_time and (datetime.now() - last_log_time).total_seconds() < 1800:
                logging.info("Too soon to log again: student_id=%s, schedule_id=%s", student_id, schedule_id)
                return "Too soon to log again"

            # Get schedule start_time
            schedule = self.get_schedule_by_id(schedule_id)
            if not schedule:
                logging.error("Invalid schedule: schedule_id=%s", schedule_id)
                return "Invalid schedule"

            current_time = datetime.now().time()
            start_time = schedule['start_time']
            end_time = schedule['end_time']

            # Determine status (aligned with schema: present, late, absent)
            status = "absent"
            if current_time <= start_time:
                status = "present"
            elif current_time <= (datetime.combine(datetime.today(), start_time) + timedelta(minutes=15)).time():
                status = "late"

            # Insert or update attendance
            scan_time = datetime.now()
            query = """
            INSERT INTO attendance (student_id, schedule_id, scan_time, status)
            VALUES (%s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE scan_time = %s, status = %s
            """
            self.cursor.execute(query, (student_id, schedule_id, scan_time, status, scan_time, status))
            self.db.commit()
            logging.info("Attendance logged: student_id=%s, schedule_id=%s, status=%s", student_id, schedule_id, status)
            return status
        except mysql.connector.Error as err:
            logging.error(f"Error logging attendance: {err}")
            self.db.rollback()
            return "Error"

    def get_last_log_time(self, student_id, schedule_id):
        """
        Get the most recent scan time for today.
        """
        try:
            query = """
            SELECT MAX(scan_time)
            FROM attendance
            WHERE student_id = %s AND schedule_id = %s AND DATE(scan_time) = CURDATE()
            """
            self.cursor.execute(query, (student_id, schedule_id))
            result = self.cursor.fetchone()
            return result[0] if result and result[0] else None
        except mysql.connector.Error as err:
            logging.error(f"Error in get_last_log_time: {err}")
            return None

    def get_user_name(self, student_id):
        """
        Get student name by student_id.
        """
        try:
            query = "SELECT name FROM students WHERE student_id = %s"
            self.cursor.execute(query, (student_id,))
            result = self.cursor.fetchone()
            return result[0] if result else None
        except mysql.connector.Error as err:
            logging.error(f"Error in get_user_name: {err}")
            return None

    def get_schedule_by_id(self, schedule_id):
        """
        Get schedule details by schedule_id.
        """
        try:
            query = "SELECT schedule_id, course_id, teacher_id, day_of_week, start_time, end_time FROM schedules WHERE schedule_id = %s"
            self.cursor.execute(query, (schedule_id,))
            row = self.cursor.fetchone()
            if row:
                columns = [column[0] for column in self.cursor.description]
                result = dict(zip(columns, row))
                logging.info("Fetched schedule: schedule_id=%s", schedule_id)
                return result
            logging.warning("No schedule found: schedule_id=%s", schedule_id)
            return None
        except mysql.connector.Error as err:
            logging.error(f"Error in get_schedule_by_id: {err}")
            return None

    def get_today_scanned_students(self):
        """
        Retrieve a sorted list of students who scanned today.
        """
        try:
            query = """
            SELECT s.name, a.scan_time
            FROM attendance a
            JOIN students s ON a.student_id = s.student_id
            WHERE DATE(a.scan_time) = CURDATE()
            ORDER BY a.scan_time ASC
            """
            self.cursor.execute(query)
            results = self.cursor.fetchall()
            scanned_students = [{"name": name, "scan_time": scan_time} for (name, scan_time) in results]
            logging.info("Fetched %d scanned students for today", len(scanned_students))
            return scanned_students
        except mysql.connector.Error as err:
            logging.error(f"Error in get_today_scanned_students: {err}")
            return []

    def close(self):
        """
        Close database connection.
        """
        try:
            self.cursor.close()
            self.db.close()
            logging.info("Database connection closed")
        except mysql.connector.Error as err:
            logging.error(f"Error closing database: {err}")
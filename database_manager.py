import mysql.connector
import numpy as np
from datetime import datetime, timedelta, time
import logging

logging.basicConfig(
    level=logging.DEBUG,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('database.log'),
        logging.StreamHandler()
    ]
)

class DatabaseManager:
    def __init__(self, host="localhost", user="root", password="paganini019", database="attend_data", port=3308):
        try:
            self.db = mysql.connector.connect(
                host=host,
                user=user,
                password=password,
                database=database,
                port=port
            )
            self.cursor = self.db.cursor()
            logging.info("Connected to attend_data database")
        except mysql.connector.Error as err:
            logging.error(f"Database connection error: {err}, Code: {err.errno}, SQLSTATE: {err.sqlstate}")
            raise

    def load_known_faces(self):
        known_face_encodings = []
        known_face_names = []
        known_face_ids = []
        try:
            self.cursor.execute("SELECT student_id, name, face_encoding FROM students WHERE face_encoding IS NOT NULL")
            for student_id, name, face_encoding in self.cursor:
                if face_encoding:
                    expected_size = 1024  # 128 floats * 8 bytes
                    if len(face_encoding) != expected_size:
                        logging.warning(f"Invalid face encoding for student {name} (ID: {student_id}): {len(face_encoding)} bytes")
                        continue
                    known_face_ids.append(student_id)
                    known_face_names.append(name)
                    known_face_encodings.append(np.frombuffer(face_encoding, dtype=np.float64))
            logging.info("Loaded %d known faces", len(known_face_encodings))
        except mysql.connector.Error as err:
            logging.error(f"Error loading known faces: {err}")
        return known_face_encodings, known_face_names, known_face_ids

    def get_current_schedule(self, student_id, course_id, schedule_id):
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
                logging.info(f"Found schedule: student_id={student_id}, course_id={course_id}, schedule_id={schedule_id}")
                schedule_id_db, start_time, end_time, course_name = result
                # Convert timedelta to time if necessary
                if isinstance(start_time, timedelta):
                    total_seconds = int(start_time.total_seconds())
                    hours, remainder = divmod(total_seconds, 3600)
                    minutes, seconds = divmod(remainder, 60)
                    start_time = time(hours, minutes, seconds)
                if isinstance(end_time, timedelta):
                    total_seconds = int(end_time.total_seconds())
                    hours, remainder = divmod(total_seconds, 3600)
                    minutes, seconds = divmod(remainder, 60)
                    end_time = time(hours, minutes, seconds)
                now = datetime.now()
                today = now.date()
                start_dt = datetime.combine(today, start_time)
                end_dt = datetime.combine(today, end_time)
                if start_dt <= now <= end_dt:
                    return (schedule_id_db, start_time, end_time, course_name), None
                logging.warning(f"Current time {now} outside schedule {start_time}-{end_time}")
                return None, "Outside schedule time"
            logging.warning(f"No schedule found: student_id={student_id}, course_id={course_id}, schedule_id={schedule_id}")
            return None, "Not enrolled or invalid schedule"
        except mysql.connector.Error as err:
            logging.error(f"Error in get_current_schedule: {err}")
            return None, str(err)

    def log_attendance(self, student_id, schedule_id):
        try:
            last_log_time = self.get_last_log_time(student_id, schedule_id)
            if last_log_time and (datetime.now() - last_log_time).total_seconds() < 1800:
                logging.info(f"Too soon to log again: student_id={student_id}, schedule_id={schedule_id}")
                return "Too soon to log again"

            schedule = self.get_schedule_by_id(schedule_id)
            if not schedule:
                logging.error(f"Invalid schedule: schedule_id={schedule_id}")
                return "Invalid schedule"

            now = datetime.now()
            today = now.date()
            start_time = schedule['start_time']
            end_time = schedule['end_time']
            # Convert timedelta to time if necessary
            if isinstance(start_time, timedelta):
                total_seconds = int(start_time.total_seconds())
                hours, remainder = divmod(total_seconds, 3600)
                minutes, seconds = divmod(remainder, 60)
                start_time = time(hours, minutes, seconds)
            if isinstance(end_time, timedelta):
                total_seconds = int(end_time.total_seconds())
                hours, remainder = divmod(total_seconds, 3600)
                minutes, seconds = divmod(remainder, 60)
                end_time = time(hours, minutes, seconds)
            start_dt = datetime.combine(today, start_time)
            late_dt = start_dt + timedelta(minutes=15)
            end_dt = datetime.combine(today, end_time)

            if now > end_dt:
                logging.warning(f"Class ended: student_id={student_id}, schedule_id={schedule_id}")
                return "Class ended"

            status = "absent"
            if now <= start_dt:
                status = "present"
            elif now <= late_dt:
                status = "late"

            query = """
            INSERT INTO attendance (student_id, schedule_id, scan_time, status)
            VALUES (%s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE scan_time = %s, status = %s
            """
            self.cursor.execute(query, (student_id, schedule_id, now, status, now, status))
            self.db.commit()
            logging.info(f"Attendance logged: student_id={student_id}, schedule_id={schedule_id}, status={status}")
            return status
        except mysql.connector.Error as err:
            logging.error(f"Error logging attendance: {err}")
            self.db.rollback()
            return "Error"

    def get_last_log_time(self, student_id, schedule_id):
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
        try:
            query = "SELECT name FROM students WHERE student_id = %s"
            self.cursor.execute(query, (student_id,))
            result = self.cursor.fetchone()
            return result[0] if result else None
        except mysql.connector.Error as err:
            logging.error(f"Error in get_user_name: {err}")
            return None

    def get_schedule_by_id(self, schedule_id):
        try:
            query = "SELECT schedule_id, course_id, teacher_id, day_of_week, start_time, end_time FROM schedules WHERE schedule_id = %s"
            self.cursor.execute(query, (schedule_id,))
            row = self.cursor.fetchone()
            if row:
                columns = [column[0] for column in self.cursor.description]
                result = dict(zip(columns, row))
                # Convert timedelta to time if necessary
                if isinstance(result['start_time'], timedelta):
                    total_seconds = int(result['start_time'].total_seconds())
                    hours, remainder = divmod(total_seconds, 3600)
                    minutes, seconds = divmod(remainder, 60)
                    result['start_time'] = time(hours, minutes, seconds)
                if isinstance(result['end_time'], timedelta):
                    total_seconds = int(result['end_time'].total_seconds())
                    hours, remainder = divmod(total_seconds, 3600)
                    minutes, seconds = divmod(remainder, 60)
                    result['end_time'] = time(hours, minutes, seconds)
                logging.info(f"Fetched schedule: schedule_id={schedule_id}")
                return result
            logging.warning(f"No schedule found: schedule_id={schedule_id}")
            return None
        except mysql.connector.Error as err:
            logging.error(f"Error in get_schedule_by_id: {err}")
            return None

    def get_today_scanned_students(self):
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
            scanned_students = [{"name": name, "scan_time": scan_time} for name, scan_time in results]
            logging.info(f"Fetched {len(scanned_students)} scanned students for today")
            return scanned_students
        except mysql.connector.Error as err:
            logging.error(f"Error in get_today_scanned_students: {err}")
            return []

    def close(self):
        try:
            self.cursor.close()
            self.db.close()
            logging.info("Database connection closed")
        except mysql.connector.Error as err:
            logging.error(f"Error closing database: {err}")
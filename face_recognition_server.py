from flask import Flask, request, jsonify
from flask_cors import CORS
import cv2
import numpy as np
import face_recognition
from database_manager import DatabaseManager
from datetime import datetime
import base64
from io import BytesIO
from PIL import Image
import logging
import sys
import traceback
from logging.handlers import RotatingFileHandler
import requests

if sys.stdout.encoding != 'utf-8':
    sys.stdout = open(sys.stdout.fileno(), mode='w', encoding='utf-8', buffering=1)

logging.basicConfig(
    level=logging.DEBUG,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        RotatingFileHandler('face_recognition_server.log', maxBytes=10*1024*1024, backupCount=5, encoding='utf-8'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

app = Flask(__name__)
CORS(app, resources={r"/*": {
    "origins": [
        "http://localhost",
        "http://127.0.0.1",
        "http://192.168.1.108",
        "http://localhost:5000",
        "http://127.0.0.1:5000",
        "http://192.168.1.108:5000"
    ],
    "methods": ["GET", "POST", "OPTIONS"],
    "allow_headers": ["Content-Type"]
}})

try:
    db_manager = DatabaseManager()
    logger.info("Database manager initialized")
except Exception as e:
    logger.error(f"Failed to initialize database: {e}")
    sys.exit(1)

known_faces = []

def load_known_faces():
    global known_faces
    known_faces.clear()
    try:
        db_manager.cursor.execute("SELECT student_id, name, face_encoding FROM students WHERE face_encoding IS NOT NULL")
        rows = db_manager.cursor.fetchall()
        for row in rows:
            student_id, name, encoding = row
            try:
                encoding_array = np.frombuffer(encoding, dtype=np.float64)
                if encoding_array.size == 128:
                    known_faces.append({
                        'id': student_id,
                        'name': name,
                        'encoding': encoding_array
                    })
                else:
                    logger.warning(f"Invalid face encoding size for student {student_id}: {encoding_array.size}")
            except Exception as e:
                logger.error(f"Error loading face encoding for student {student_id}: {e}")
        logger.info(f"Loaded {len(known_faces)} known faces")
    except Exception as e:
        logger.error(f"Error loading known faces: {e}")

try:
    load_known_faces()
except Exception as e:
    logger.error(f"Failed to load known faces at startup: {e}")
    sys.exit(1)

@app.route('/test', methods=['GET'])
def test():
    logger.info("Test endpoint called")
    return jsonify({"status": "Flask server is running", "timestamp": datetime.now().isoformat()})

@app.route('/start_scan', methods=['POST'])
def start_scan():
    try:
        logger.info("Received request to /start_scan")
        data = request.get_json()
        logger.info(f"Request data: {data}")
        course_id = data.get('course_id')
        teacher_id = data.get('teacher_id')
        schedule_id = data.get('schedule_id')

        if not all([course_id, teacher_id, schedule_id]):
            logger.error(f"Missing parameters: course_id={course_id}, teacher_id={teacher_id}, schedule_id={schedule_id}")
            return jsonify({"error": "Missing required parameters"}), 400

        query = """
        SELECT c.teacher_id, s.schedule_id
        FROM courses c
        JOIN schedules s ON c.course_id = s.course_id
        WHERE c.course_id = %s AND c.teacher_id = %s AND s.schedule_id = %s
        """
        db_manager.cursor.execute(query, (course_id, teacher_id, schedule_id))
        result = db_manager.cursor.fetchone()
        if not result:
            logger.error(f"Invalid course, teacher, or schedule: course_id={course_id}, teacher_id={teacher_id}, schedule_id={schedule_id}")
            return jsonify({"error": "Invalid course, teacher, or schedule"}), 403

        logger.info(f"Scanning started: course_id={course_id}, teacher_id={teacher_id}, schedule_id={schedule_id}")
        return jsonify({"status": "Scanning started", "course_id": course_id, "schedule_id": schedule_id})
    except Exception as e:
        logger.error(f"Error in start_scan: {str(e)}\n{traceback.format_exc()}")
        return jsonify({"error": str(e)}), 500

@app.route('/process_frame', methods=['POST'])
def process_frame():
    try:
        logger.info("Received request to /process_frame")
        data = request.get_json()
        logger.debug(f"Request data keys: {list(data.keys())}")
        frame_data = data.get('frame')
        course_id = data.get('course_id')
        schedule_id = data.get('schedule_id')

        if not all([frame_data, course_id, schedule_id]):
            logger.error(f"Missing parameters: frame={bool(frame_data)}, course_id={course_id}, schedule_id={schedule_id}")
            return jsonify({"error": "Missing required parameters"}), 400

        if not frame_data.startswith('data:image/'):
            logger.error(f"Invalid frame_data format: {frame_data[:50]}")
            return jsonify({"error": "Invalid frame data format"}), 400

        try:
            img_data = base64.b64decode(frame_data.split(',')[1])
        except Exception as e:
            logger.error(f"Base64 decode error: {e}")
            return jsonify({"error": "Invalid base64 data"}), 400

        try:
            img = Image.open(BytesIO(img_data))
            frame = np.array(img)
        except Exception as e:
            logger.error(f"Image open error: {e}")
            return jsonify({"error": "Failed to open image"}), 400

        if frame.ndim != 3 or frame.shape[2] != 3:
            logger.error(f"Invalid frame shape: {frame.shape}")
            return jsonify({"error": "Invalid frame dimensions"}), 400

        try:
            frame = cv2.cvtColor(frame, cv2.COLOR_RGB2BGR)
            rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        except Exception as e:
            logger.error(f"Color conversion error: {e}")
            return jsonify({"error": "Color conversion failed"}), 400

        try:
            face_locations = face_recognition.face_locations(rgb_frame)
            face_encodings = face_recognition.face_encodings(rgb_frame, face_locations)
            logger.info(f"Detected {len(face_locations)} faces")
        except Exception as e:
            logger.error(f"Face recognition error: {e}")
            return jsonify({"error": "Face recognition failed"}), 500

        results = []
        for (top, right, bottom, left), face_encoding in zip(face_locations, face_encodings):
            name = "Unknown"
            student_id = None

            if known_faces:
                try:
                    matches = face_recognition.compare_faces(
                        [face['encoding'] for face in known_faces], face_encoding
                    )
                    if True in matches:
                        first_match_index = matches.index(True)
                        name = known_faces[first_match_index]['name']
                        student_id = known_faces[first_match_index]['id']
                except Exception as e:
                    logger.error(f"Face comparison error: {e}")
                    continue
            else:
                logger.warning("No known faces available")

            attendance_text = "No attendance record"
            reason = None
            if student_id is not None:
                try:
                    current_schedule, reason = db_manager.get_current_schedule(student_id, course_id, schedule_id)
                    logger.debug(f"Current schedule for student {student_id}: {current_schedule}, reason: {reason}")
                    if current_schedule:
                        schedule_id_db, start_time, end_time, course_name = current_schedule
                        if str(schedule_id_db) == str(schedule_id):
                            status = db_manager.log_attendance(student_id, schedule_id, status='present')
                            logger.debug(f"Attendance status for student {student_id}: {status}")
                            if status == "Too soon to log again":
                                attendance_text = f"ลงชื่อซ้ำเร็วเกินไปสำหรับ {course_name}"
                            elif status == "Error":
                                attendance_text = f"บันทึกการเข้างานล้มเหลว: {reason or 'เกิดข้อผิดพลาดในฐานข้อมูล'}"
                            elif status == "Class ended":
                                attendance_text = f"คลาสสิ้นสุดแล้ว: {course_name}"
                            else:
                                attendance_text = f"บันทึกการเข้างานสำหรับ {course_name} สถานะ: {status}"
                        else:
                            attendance_text = f"รหัสตารางไม่ตรงกัน: {reason or 'ตารางไม่สอดคล้อง'}"
                    else:
                        if reason == "Outside schedule time":
                            # Log absent status for late scans
                            status = db_manager.log_attendance(student_id, schedule_id, status='absent')
                            attendance_text = f"ขาด: ไม่อยู่ในช่วงเวลาคลาส"
                            logger.debug(f"Logged absent status for student {student_id} due to late scan")
                        elif reason == "Not enrolled or invalid schedule":
                            attendance_text = "ไม่อยู่ในรายชื่อการลงทะเบียนเรียน"
                        else:
                            attendance_text = f"ข้อผิดพลาด: {reason or 'ไม่พบข้อมูลตาราง'}"
                except Exception as e:
                    reason = str(e)
                    logger.error(f"Attendance logging error for student {student_id}: {str(e)}\n{traceback.format_exc()}")
                    attendance_text = f"บันทึกการเข้างานล้มเหลว: {reason}"

            results.append({
                "name": name,
                "attendance_text": attendance_text,
                "box": {"top": top, "right": right, "bottom": bottom, "left": left},
                "reason": reason
            })

        logger.info(f"Processed frame: course_id={course_id}, schedule_id={schedule_id}, results={len(results)}")
        return jsonify({"results": results})
    except Exception as e:
        logger.error(f"Error in process_frame: {str(e)}\n{traceback.format_exc()}")
        return jsonify({"error": str(e)}), 500

@app.route('/stop_scan', methods=['POST'])
def stop_scan():
    try:
        logger.info("Received request to /stop_scan")
        return jsonify({"status": "Scanning stopped"})
    except Exception as e:
        logger.error(f"Error in stop_scan: {str(e)}\n{traceback.format_exc()}")
        return jsonify({"error": str(e)}), 500

@app.route('/reload_faces', methods=['POST'])
def reload_faces():
    try:
        logger.info("Received request to /reload_faces")
        load_known_faces()
        return jsonify({"status": "success", "message": f"Reloaded {len(known_faces)} faces"})
    except Exception as e:
        logger.error(f"Error reloading faces: {str(e)}\n{traceback.format_exc()}")
        return jsonify({"status": "error", "message": str(e)}), 500

if __name__ == "__main__":
    try:
        logger.info("Starting Flask server...")
        app.run(host="0.0.0.0", port=5000, debug=True)
    except Exception as e:
        logger.error(f"Failed to start Flask server: {str(e)}\n{traceback.format_exc()}")
    finally:
        logger.info("Closing database connection...")
        db_manager.close()
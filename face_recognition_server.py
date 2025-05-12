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

# Setup logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

app = Flask(__name__)
CORS(app, resources={r"/*": {"origins": "http://localhost"}})  # Allow XAMPP frontend

# Initialize database manager and load known faces
db_manager = DatabaseManager()
known_face_encodings, known_face_names, known_face_ids = db_manager.load_known_faces()

@app.route('/start_scan', methods=['POST'])
def start_scan():
    data = request.get_json()
    course_id = data.get('course_id')
    teacher_id = data.get('teacher_id')
    schedule_id = data.get('schedule_id')

    if not all([course_id, teacher_id, schedule_id]):
        logging.error("Missing parameters: course_id=%s, teacher_id=%s, schedule_id=%s", course_id, teacher_id, schedule_id)
        return jsonify({"error": "Missing required parameters"}), 400

    # Validate course, teacher, and schedule
    query = """
    SELECT c.teacher_id, s.schedule_id
    FROM courses c
    JOIN schedules s ON c.course_id = s.course_id
    WHERE c.course_id = %s AND c.teacher_id = %s AND s.schedule_id = %s
    """
    try:
        db_manager.cursor.execute(query, (course_id, teacher_id, schedule_id))
        result = db_manager.cursor.fetchone()
        if not result:
            logging.error("Invalid course, teacher, or schedule: course_id=%s, teacher_id=%s, schedule_id=%s", course_id, teacher_id, schedule_id)
            return jsonify({"error": "Invalid course, teacher, or schedule"}), 403
    except Exception as e:
        logging.error("Database error in start_scan: %s", e)
        return jsonify({"error": "Database error"}), 500

    logging.info("Scanning started: course_id=%s, teacher_id=%s, schedule_id=%s", course_id, teacher_id, schedule_id)
    return jsonify({"status": "Scanning started", "course_id": course_id, "schedule_id": schedule_id})

@app.route('/process_frame', methods=['POST'])
def process_frame():
    data = request.get_json()
    frame_data = data.get('frame')
    course_id = data.get('course_id')
    schedule_id = data.get('schedule_id')

    if not all([frame_data, course_id, schedule_id]):
        logging.error("Missing parameters in process_frame: frame=%s, course_id=%s, schedule_id=%s", bool(frame_data), course_id, schedule_id)
        return jsonify({"error": "Missing required parameters"}), 400

    try:
        # Decode the base64 frame
        img_data = base64.b64decode(frame_data.split(',')[1])
        img = Image.open(BytesIO(img_data))
        frame = np.array(img)
        frame = cv2.cvtColor(frame, cv2.COLOR_RGB2BGR)

        # Process the frame for face recognition
        rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        face_locations = face_recognition.face_locations(rgb_frame)
        face_encodings = face_recognition.face_encodings(rgb_frame, face_locations)

        results = []
        for (top, right, bottom, left), face_encoding in zip(face_locations, face_encodings):
            matches = face_recognition.compare_faces(known_face_encodings, face_encoding)
            name = "Unknown"
            student_id = None

            if True in matches:
                first_match_index = matches.index(True)
                name = known_face_names[first_match_index]
                student_id = known_face_ids[first_match_index]

            # Log attendance if a student is recognized
            attendance_text = "No attendance record"
            if student_id is not None:
                current_schedule = db_manager.get_current_schedule(student_id, course_id, schedule_id)
                if current_schedule:
                    schedule_id_db, start_time, end_time, course_name = current_schedule
                    if str(schedule_id_db) == str(schedule_id):
                        status = db_manager.log_attendance(student_id, schedule_id)
                        if status == "Too soon to log again":
                            attendance_text = f"Attendance recently logged for {course_name}"
                        elif status == "Error":
                            attendance_text = "Failed to log attendance"
                        else:
                            attendance_text = f"Attendance logged for {course_name}. Status: {status}"
                    else:
                        attendance_text = "Schedule ID mismatch"
                else:
                    attendance_text = "No matching schedule"

            results.append({
                "name": name,
                "attendance_text": attendance_text,
                "box": {"top": top, "right": right, "bottom": bottom, "left": left}
            })

        logging.info("Processed frame: course_id=%s, schedule_id=%s, results=%d", course_id, schedule_id, len(results))
        return jsonify({"results": results})
    except Exception as e:
        logging.error("Error in process_frame: %s", e)
        return jsonify({"error": str(e)}), 500

@app.route('/stop_scan', methods=['POST'])
def stop_scan():
    logging.info("Scanning stopped")
    return jsonify({"status": "Scanning stopped"})

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000, debug=True)
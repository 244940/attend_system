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

app = Flask(__name__)
CORS(app)  # Enable CORS to allow requests from the web app

# Initialize database manager and load known faces
db_manager = DatabaseManager()
known_face_encodings, known_face_names, known_face_ids = db_manager.load_known_faces()

@app.route('/start_scan', methods=['POST'])
def start_scan():
    data = request.get_json()
    course_id = data.get('course_id')
    teacher_id = data.get('teacher_id')

    # Validate course and teacher
    query = """
    SELECT teacher_id FROM courses WHERE course_id = %s
    """
    db_manager.cursor.execute(query, (course_id,))
    result = db_manager.cursor.fetchone()
    if not result or result[0] != teacher_id:
        return jsonify({"error": "Invalid course or teacher"}), 403

    return jsonify({"status": "Scanning started", "course_id": course_id})

@app.route('/process_frame', methods=['POST'])
def process_frame():
    data = request.get_json()
    frame_data = data.get('frame')  # Base64 encoded frame
    course_id = data.get('course_id')

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
        user_id = None

        if True in matches:
            first_match_index = matches.index(True)
            name = known_face_names[first_match_index]
            user_id = known_face_ids[first_match_index]

        # Log attendance if a user is recognized
        attendance_text = "No attendance record"
        if user_id is not None:
            current_schedule = db_manager.get_current_schedule(user_id)
            if current_schedule and str(current_schedule[0]) == str(course_id):  # Match schedule with course
                schedule_id, start_time, end_time, course_name = current_schedule
                status = db_manager.log_attendance(user_id, schedule_id)
                if status == "Too soon to log again":
                    attendance_text = f"Attendance recently logged for {course_name}"
                else:
                    attendance_text = f"Attendance logged for {course_name}. Status: {status}"
            else:
                attendance_text = "No matching schedule"

        results.append({
            "name": name,
            "attendance_text": attendance_text,
            "box": {"top": top, "right": right, "bottom": bottom, "left": left}
        })

    return jsonify({"results": results})

@app.route('/stop_scan', methods=['POST'])
def stop_scan():
    return jsonify({"status": "Scanning stopped"})

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000)
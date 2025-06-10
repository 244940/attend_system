from flask import Flask, request, jsonify
from flask_cors import CORS
import smtplib
from email.mime.text import MIMEText
import logging
from logging.handlers import RotatingFileHandler
from dotenv import load_dotenv
import os

load_dotenv()

app = Flask(__name__)
CORS(app, resources={r"/*": {
    "origins": [
        "http://localhost",
        "http://127.0.0.1",
        "http://192.168.1.108",
        "http://localhost:5000",
        "http://127.0.0.1:1000",
        "http://192.168.1.108:5000"
    ],
    "methods": ["GET", "POST", "OPTIONS"],
    "allow_headers": ["Content-Type"]
}})

logging.basicConfig(
    level=logging.DEBUG,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        RotatingFileHandler('face_recognition_server.log', maxBytes=10*1024*1024, backupCount=5, encoding='utf-8'),
        logging.StreamHandler()
    ]
)

@app.route('/api/send-email', methods=['POST'])
def send_email():
    try:
        data = request.get_json()
        to_email = data.get('to')
        subject = data.get('subject')
        body_template = data.get('body_template')
        course_name = data.get('course_name')

        if not all([to_email, subject, body_template]):
            return jsonify({'error': 'Missing required fields'}), 400

        smtp_server = 'smtp.gmail.com'
        smtp_port = 587
        smtp_user = os.getenv('SMTP_USER')
        smtp_password = os.getenv('SMTP_PASSWORD')

        if not smtp_user or not smtp_password:
            return jsonify({'error': 'SMTP configuration missing'}), 500

        msg = MIMEText(body_template, 'plain', 'utf-8')
        msg['Subject'] = subject
        msg['From'] = smtp_user
        msg['To'] = to_email

        with smtplib.SMTP(smtp_server, smtp_port) as server:
            server.starttls()
            server.login(smtp_user, smtp_password)
            server.send_message(msg)

        logging.info(f"Email sent to {to_email} for course {course_name}")
        return jsonify({'message': 'Email sent successfully'}), 200

    except Exception as e:
        logging.error(f"Failed to send email: {str(e)}")
        return jsonify({'error': str(e)}), 500

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5001, debug=True)
from flask import Flask, request, jsonify
import smtplib
from email.mime.text import MIMEText
import logging
from logging.handlers import RotatingFileHandler
import os
from dotenv import load_dotenv

load_dotenv()  # โหลดตัวแปรจาก .env

app = Flask(__name__)

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        RotatingFileHandler('email_service.log', maxBytes=10*1024*1024, backupCount=5, encoding='utf-8'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

@app.route('/api/send-email', methods=['POST'])
def send_email():
    try:
        data = request.get_json()
        to = data.get('to')
        subject = data.get('subject')
        body_template = data.get('body_template')
        course_name = data.get('course_name')

        if not all([to, subject, body_template, course_name]):
            logger.error(f"Missing email parameters: to={to}, subject={subject}, course_name={course_name}")
            return jsonify({"error": "Missing required parameters"}), 400

        smtp_user = os.getenv('SMTP_USER')
        smtp_password = os.getenv('SMTP_PASSWORD')
        if not smtp_user or not smtp_password:
            logger.error("SMTP credentials not configured")
            return jsonify({"error": "SMTP configuration missing"}), 500

        msg = MIMEText(body_template, 'plain', 'utf-8')
        msg['Subject'] = subject
        msg['From'] = smtp_user
        msg['To'] = to

        with smtplib.SMTP_SSL('smtp.gmail.com', 465) as server:
            server.login(smtp_user, smtp_password)
            server.sendmail(smtp_user, to, msg.as_string())

        logger.info(f"Email sent to {to} for course {course_name}")
        return jsonify({"message": "Email sent successfully"}), 200
    except Exception as e:
        logger.error(f"Failed to send email: {str(e)}")
        return jsonify({"error": str(e)}), 500

if __name__ == '__main__':
    logger.info("Starting email service...")
    app.run(host='0.0.0.0', port=5001, debug=True)
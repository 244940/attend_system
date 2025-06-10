import os
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from flask import Flask, request, jsonify
from flask_cors import CORS
from dotenv import load_dotenv

# โหลด Environment Variables จากไฟล์ .env
load_dotenv()
print("EMAIL:", os.getenv("EMAIL_USER"))
print("PASS :", os.getenv("EMAIL_PASSWORD"))

app = Flask(__name__)
CORS(app)

# ดึงค่าจาก .env
SENDER_EMAIL = os.getenv("EMAIL_USER")
SENDER_PASSWORD = os.getenv("EMAIL_PASSWORD")

# ตรวจสอบว่า .env ถูกตั้งค่าถูกต้อง
if not SENDER_EMAIL or not SENDER_PASSWORD:
    print("❌ ERROR: EMAIL_USER or EMAIL_PASSWORD not set in .env file.")
    exit(1)

@app.route('/api/send-email', methods=['POST'])
def send_email():
    try:
        data = request.get_json()
        recipient_email = data.get('to')
        subject = data.get('subject')
        body = data.get('body')
        is_html = data.get('is_html', False)

        if not recipient_email or not subject or not body:
            return jsonify({"error": "Missing required fields: to, subject, body"}), 400

        # สร้างอีเมล
        msg = MIMEMultipart()
        msg['From'] = SENDER_EMAIL
        msg['To'] = recipient_email
        msg['Subject'] = subject
        msg.attach(MIMEText(body, 'html' if is_html else 'plain'))

        # ตั้งค่า Gmail SMTP
        with smtplib.SMTP('smtp.gmail.com', 587) as server:
            server.ehlo()
            server.starttls()
            server.login(SENDER_EMAIL, SENDER_PASSWORD)
            server.send_message(msg)

        return jsonify({"message": "✅ Email sent successfully!"}), 200

    except Exception as e:
        print(f"❌ Error sending email: {e}")
        return jsonify({"error": f"Email send failed: {str(e)}"}), 500

@app.route('/api/status', methods=['GET'])
def status():
    return jsonify({"status": "Backend is running"}), 200

if __name__ == '__main__':
    app.run(debug=True, port=5000)

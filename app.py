import os
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from flask import Flask, request, jsonify
from flask_cors import CORS
from dotenv import load_dotenv
from datetime import datetime # เพิ่มการ import datetime

# โหลด Environment Variables จากไฟล์ .env
load_dotenv()
print("EMAIL:", os.getenv("EMAIL_USER"))
print("PASS :", os.getenv("EMAIL_PASSWORD"))

app = Flask(__name__)
CORS(app)

# ดึงค่าจาก .env
SENDER_EMAIL = os.getenv("EMAIL_USER")
SENDER_PASSWORD = os.getenv("EMAIL_PASSWORD")
SMTP_HOST = os.getenv("SMTP_HOST", "smtp.gmail.com") # เพิ่ม
SMTP_PORT = int(os.getenv("SMTP_PORT", 587)) # เพิ่ม
USE_SSL = os.getenv("USE_SSL", "False").lower() == "true" # เพิ่ม
SMTP_DEBUG_LEVEL = int(os.getenv("SMTP_DEBUG_LEVEL", 0)) # เพิ่ม

# ตรวจสอบว่า .env ถูกตั้งค่าถูกต้อง
if not SENDER_EMAIL or not SENDER_PASSWORD:
    print("❌ ERROR: EMAIL_USER or EMAIL_PASSWORD not set in .env file.")
    exit(1)

# API Endpoint สำหรับส่งอีเมล
@app.route('/api/send-email', methods=['POST'])
def send_email():
    try:
        data = request.get_json()
        recipient_email = data.get('to')
        subject = data.get('subject')
        body_template = data.get('body_template') # รับ body_template
        course_name_from_php = data.get('course_name') # รับชื่อวิชาจาก PHP

        if not recipient_email or not subject or not body_template or not course_name_from_php:
            return jsonify({"error": "Missing required fields: 'to', 'subject', 'body_template', 'course_name'"}), 400

        # เตรียมเนื้อหาอีเมลโดยแทนที่ placeholder
        # เราจะใช้อีเมลเป็นชื่อแทนในอีเมลโดยตรงตามที่ลูกค้าร้องขอ
        currentDateTime = datetime.now()
        date_formatted = currentDateTime.strftime('%d/%m/%Y')
        time_formatted = currentDateTime.strftime('%H:%M') # เปลี่ยนเป็น HH:MM ตามที่ระบุใน PHP

        final_body = body_template.replace('(ชื่อนิสิต)', recipient_email)
        final_body = final_body.replace('(วัน/เดือน/ปี)', date_formatted)
        final_body = final_body.replace('เวลา...', time_formatted + ' น.') # เพิ่ม "น." และให้แสดงเวลาจริง

        # เนื่องจาก PHP ได้สร้างเนื้อหาที่รวมชื่อวิชาแล้ว เราไม่จำเป็นต้องแทนที่อีก
        # แต่ถ้าต้องการควบคุมข้อความ 'ขอบคุณที่มาเข้าเรียนตรงเวลา' ใน Flask ก็สามารถทำได้
        # final_body = final_body.replace('ขอบคุณที่มาเข้าเรียนตรงเวลา', f'ขอบคุณที่มาเข้าเรียนวิชา {course_name_from_php} ตรงเวลา')

        # สร้างอีเมล
        msg = MIMEMultipart()
        msg['From'] = SENDER_EMAIL
        msg['To'] = recipient_email
        msg['Subject'] = subject
        msg.attach(MIMEText(final_body, 'plain', 'utf-8')) # ส่งเป็น plain text (หรือ 'html' ถ้า is_html เป็น true)

        # ตั้งค่า SMTP Server (ใช้ค่าจาก .env ที่เพิ่มเข้ามา)
        if USE_SSL:
            server = smtplib.SMTP_SSL(SMTP_HOST, SMTP_PORT)
        else:
            server = smtplib.SMTP(SMTP_HOST, SMTP_PORT)
            server.starttls()

        server.set_debuglevel(SMTP_DEBUG_LEVEL)

        server.login(SENDER_EMAIL, SENDER_PASSWORD)
        server.send_message(msg)
        server.quit()

        return jsonify({"message": "✅ Email sent successfully!"}), 200

    except smtplib.SMTPAuthenticationError:
        print(f"SMTP Authentication Error: Check EMAIL_USER and EMAIL_PASSWORD in .env. Using App Password for Gmail?")
        return jsonify({"error": "Authentication failed. Check your email credentials."}), 401
    except smtplib.SMTPConnectError as e:
        print(f"SMTP Connection Error: Could not connect to SMTP server. Check host, port, and firewall. Error: {e}")
        return jsonify({"error": f"Failed to connect to email server: {str(e)}"}), 500
    except smtplib.SMTPServerDisconnected as e:
        print(f"SMTP Server Disconnected: {e}")
        return jsonify({"error": f"Email server disconnected unexpectedly: {str(e)}"}), 500
    except Exception as e:
        print(f"❌ Error sending email: {e}")
        return jsonify({"error": f"Email send failed: {str(e)}"}), 500

@app.route('/api/status', methods=['GET'])
def status():
    return jsonify({"status": "Backend is running"}), 200

if __name__ == '__main__':
    app.run(debug=True, port=3308)

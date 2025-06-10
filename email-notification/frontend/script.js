document.addEventListener('DOMContentLoaded', () => {
    // เลือกองค์ประกอบ HTML ที่เกี่ยวข้อง
    const studentEmailInput = document.getElementById('studentEmail');
    const defaultStudentSubjectInput = document.getElementById('defaultStudentSubject');
    const defaultStudentBodyTextarea = document.getElementById('defaultStudentBody');
    const confirmStudentCheckInBtn = document.getElementById('confirmStudentCheckInBtn');
    const statusMessageDisplay = document.getElementById('status-message-display'); // ใช้ id นี้ตาม HTML ใหม่
    const courseSelect = document.getElementById('courseSelect'); // เพิ่มการเลือกวิชา

    // Backend API Endpoint
    const backendUrl = 'http://127.0.0.1:5000/api/send-email'; 

    // ฟังก์ชันแสดงสถานะบนหน้าเว็บ (ข้อความแจ้งเตือนสีเขียว/แดง)
    function showStatus(message, type = 'info') {
        statusMessageDisplay.textContent = message;
        statusMessageDisplay.className = `status-message ${type}`;
        statusMessageDisplay.style.display = 'block';
        setTimeout(() => {
            statusMessageDisplay.style.display = 'none';
            statusMessageDisplay.textContent = '';
            statusMessageDisplay.className = 'status-message';
        }, 8000); // แสดงข้อความ 8 วินาที
    }

    // ฟังก์ชันส่งอีเมลเมื่อกดปุ่ม "ยืนยันการเข้าเรียน"
    async function sendAttendanceEmail() { // เปลี่ยนชื่อฟังก์ชันเพื่อให้ชัดเจนขึ้น
        const studentEmail = studentEmailInput.value.trim();
        const subject = defaultStudentSubjectInput.value.trim();
        let body = defaultStudentBodyTextarea.value.trim();
        const selectedCourse = courseSelect.value; // ดึงค่าวิชาที่เลือก
        const currentDateTime = new Date();
        const date = currentDateTime.toLocaleDateString('th-TH', { 
            year: 'numeric', month: 'long', day: 'numeric' 
        });
        const time = currentDateTime.toLocaleTimeString('th-TH', { 
            hour: '2-digit', minute: '2-digit', second: '2-digit' 
        });

        // ตรวจสอบข้อมูลที่จำเป็น
        if (!studentEmail) {
            showStatus('กรุณากรอกอีเมลนิสิตของคุณ', 'error');
            return;
        }
        if (!subject) {
            showStatus('กรุณากรอกหัวข้ออีเมล', 'error');
            return;
        }
        if (!body) {
            showStatus('กรุณากรอกเนื้อหาอีเมล', 'error');
            return;
        }
        if (!selectedCourse) {
            showStatus('กรุณาเลือกวิชา', 'error');
            return;
        }

        // แทนที่ placeholder ในเนื้อหาอีเมล
        // คุณอาจจะต้องดึงชื่อนิสิตจากฐานข้อมูลใน Backend เพื่อใช้ในอีเมล
        body = body.replace('(ชื่อนิสิต)', 'นิสิต'); // หรือใช้ชื่อจริงถ้ามี
        body = body.replace('(วัน/เดือน/ปี)', date);
        body = body.replace('เวลา...', time);
        body = body.replace('ขอบคุณที่มาเข้าเรียนตรงเวลา', `ขอบคุณที่มาเข้าเรียนวิชา ${selectedCourse} ตรงเวลา`); // อัปเดตข้อความให้รวมวิชา

        // แสดงสถานะกำลังส่ง
        showStatus('กำลังส่งอีเมลยืนยันการเข้าเรียน...', 'info');
        confirmStudentCheckInBtn.disabled = true; // ปิดปุ่มชั่วคราวเพื่อป้องกันการกดซ้ำ

        try {
            const response = await fetch(backendUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    to: studentEmail,
                    subject: subject,
                    body: body,
                    is_html: false // ตั้งค่าเป็น true ถ้าเนื้อหาเป็น HTML
                }),
            });

            const result = await response.json();
            if (response.ok) {
                showStatus(`ส่งอีเมลสำเร็จ! ${result.message}`, 'success');
                // คุณสามารถเลือกล้างฟอร์มหรือไม่ก็ได้หลังจากส่งสำเร็จ
                // studentEmailInput.value = '';
                // courseSelect.value = ''; // รีเซ็ต dropdown
                // defaultStudentSubjectInput.value = "แจ้งเตือนการเข้าเรียนของนิสิตมหาวิทยาลัยเกษตรศาสตร์";
                // defaultStudentBodyTextarea.value = "เรียน (ชื่อนิสิต)\n\nระบบได้บันทึกว่าคุณได้เข้าเรียน (วัน/เดือน/ปี) เวลา...\nขอบคุณที่มาเข้าเรียนตรงเวลา";
            } else {
                showStatus(`ข้อผิดพลาดในการส่งอีเมล: ${result.error}`, 'error');
                console.error('Backend error:', result.error);
            }
        } catch (error) {
            console.error('Network error during email sending:', error);
            showStatus('ข้อผิดพลาดเครือข่าย. โปรดตรวจสอบการเชื่อมต่อหรือเซิร์ฟเวอร์ Backend.', 'error');
        } finally {
            confirmStudentCheckInBtn.disabled = false; // เปิดปุ่มเมื่อเสร็จสิ้น
        }
    }

    // Event Listener สำหรับปุ่ม "ยืนยันการเข้าเรียน"
    confirmStudentCheckInBtn.addEventListener('click', sendAttendanceEmail);
});
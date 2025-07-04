let video, canvas, ctx, scanning = false, stream;
const alertedStudents = new Set(); //กันการแจ้งเตือนซ้ำ 

async function fetchWithRetry(url, options, retries = 3, delay = 1000, timeout = 20000) {
    for (let i = 0; i <= retries; i++) {
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), timeout);
            console.log(`Attempting fetch to ${url}, attempt ${i + 1}`);
            const response = await fetch(url, { ...options, signal: controller.signal });
            clearTimeout(timeoutId);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return await response.json();
        } catch (error) {
            console.error(`Retrying ${url}... (${retries - i} attempts left): ${error.message}`);
            if (i === retries) throw error;
            await new Promise(resolve => setTimeout(resolve, delay));
        }
    }
}

async function initializeVideo() {
    console.log('Attempting to access webcam...');
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: true });
        video = document.getElementById('videoFeed');
        video.srcObject = stream;
        console.log('Webcam accessed successfully');
        video.onloadedmetadata = () => {
            console.log(`Video metadata loaded: ${video.videoWidth}x${video.videoHeight}`);
            canvas = document.getElementById('overlayCanvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx = canvas.getContext('2d');
        };
    } catch (error) {
        console.error('Error accessing webcam:', error);
        showToast('ไม่สามารถเข้าถึงกล้องได้: ' + error.message, 'danger');
    }
}

async function startFaceScan(teacherId, scheduleId) {
    console.log('startFaceScan called with:', { teacherId, scheduleId, currentCourseId: window.currentCourseId });
    console.log('Frontend origin:', window.location.origin);

    if (!video || !video.srcObject) {
        console.log('Initializing video...');
        await initializeVideo();
        if (!video || !video.srcObject) {
            showToast('ไม่สามารถเริ่มกล้องได้', 'danger');
            return;
        }
    }

    const currentCourseId = window.currentCourseId || null;
    let currentScheduleId = scheduleId || window.currentScheduleId || null;

    if (!window.courses || !Array.isArray(window.courses) || window.courses.length === 0) {
        console.error('No courses available:', window.courses);
        showToast('ไม่พบข้อมูลรายวิชา', 'danger');
        return;
    }

    const course = window.courses.find(c => c.course_id == currentCourseId);
    if (!course) {
        console.error('Course not found for ID:', currentCourseId);
        showToast(`ไม่พบวิชา (ID: ${currentCourseId})`, 'danger');
        return;
    }

    if (!currentScheduleId) {
        console.log('No valid scheduleId, attempting fallback');
        const fallbackSchedule = course.schedules[0]?.schedule_id || null;
        window.currentScheduleId = fallbackSchedule;
        if (!fallbackSchedule) {
            console.log('No schedules available');
            showToast('ไม่พบตารางเรียนสำหรับวิชานี้', 'danger');
            return;
        }
        currentScheduleId = fallbackSchedule;
    }

    const selectedDate = document.getElementById('selectedDate')?.value;
    const selectedDay = selectedDate ? new Date(selectedDate).toLocaleString('en-US', { weekday: 'long' }) : null;
    const courseDay = course.schedules.find(s => s.schedule_id == currentScheduleId)?.day_of_week || course.schedules[0]?.day_of_week;

    if (selectedDay && courseDay && selectedDay !== courseDay) {
        console.log(`Warning: Selected day (${selectedDay}) does not match course day (${courseDay})`);
        showToast(`วันนี้ (${getDayNameThai(selectedDay)}) ไม่ใช่วันเรียน (${getDayNameThai(courseDay)}) แต่จะสแกนต่อ`, 'warning');
    }

    console.log('Starting scan with:', { course_id: currentCourseId, teacher_id: teacherId, schedule_id: currentScheduleId });
    document.getElementById('scanResult').innerText = 'กำลังเริ่มการสแกน...';
    document.getElementById('scanResult').style.color = 'black';

    const url = 'http://127.0.0.1:5000/start_scan';
    try {
        const data = await fetchWithRetry(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                course_id: currentCourseId,
                teacher_id: teacherId,
                schedule_id: currentScheduleId
            })
        });
        console.log('Scan started:', data);
        if (data.error) throw new Error(data.error);
        scanning = true;
        document.getElementById('startScanBtn').style.display = 'none';
        document.getElementById('stopScanBtn').style.display = 'inline-block';
        document.getElementById('scanResult').innerText = 'กำลังสแกนใบหน้า...';
        document.getElementById('scanResult').style.color = 'green';
        processFrames();
    } catch (error) {
        console.error(`Failed to connect to ${url}:`, error);
        let errorMessage = 'ไม่สามารถเริ่มการสแกนได้: เซิร์ฟเวอร์ไม่ตอบสนอง';
        if (error.name === 'AbortError') {
            errorMessage = 'การเชื่อมต่อเซิร์ฟเวอร์หมดเวลา';
        } else if (error.message.includes('HTTP')) {
            errorMessage = `ข้อผิดพลาดเซิร์ฟเวอร์: ${error.message}`;
        }
        showToast(errorMessage, 'danger');
        document.getElementById('scanResult').innerHTML = `
            ${errorMessage}
            <button onclick="startFaceScan('${teacherId}', ${currentScheduleId})" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded ml-2">
                ลองใหม่
            </button>
        `;
        document.getElementById('scanResult').style.color = 'red';
    }
}

async function processFrames() {
    if (!scanning || !video || !canvas) return;

    try {
        const MAX_WIDTH = 640;
        let scale = 1;
        if (canvas.width > MAX_WIDTH) {
            scale = MAX_WIDTH / canvas.width;
            canvas.width = MAX_WIDTH;
            canvas.height = canvas.height * scale;
        }
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const frameData = canvas.toDataURL('image/jpeg', 0.4);
        console.log(`Frame data size: ${frameData.length} bytes`);

        const url = 'http://127.0.0.1:5000/process_frame';
        const response = await fetchWithRetry(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                frame: frameData,
                course_id: window.currentCourseId,
                schedule_id: window.currentScheduleId
            })
        });

        if (response.error) {
            console.error('Server error:', response.error);
            showToast(`ข้อผิดพลาดจากเซิร์ฟเวอร์: ${response.error}`, 'danger');
            return;
        }

        const results = response.results || [];
        console.log('Frame processing results:', results);

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        for (const result of results) {
            const { top, right, bottom, left } = result.box;
            ctx.strokeStyle = result.name === 'Unknown' ? 'red' : (result.attendance_text.includes('ขาด') ? 'red' : (result.attendance_text.includes('มาสาย') ? 'yellow' : 'green'));
            ctx.lineWidth = 2;
            ctx.strokeRect(left * scale, top * scale, (right - left) * scale, (bottom - top) * scale);
            ctx.fillStyle = ctx.strokeStyle;
            ctx.font = '16px Sarabun';
            ctx.fillText(`${result.name} (${result.attendance_text})`, left * scale, (top - 10) * scale);
            showToast(result.attendance_text, result.attendance_text.includes('ขาด') ? 'danger' : (result.attendance_text.includes('มาสาย') ? 'warning' : 'success'));
        }

        document.getElementById('scanResult').innerText = `ตรวจพบ ${results.length} ใบหน้า`;
    } catch (error) {
        console.error('Error processing frame:', error);
        showToast('ข้อผิดพลาดในการประมวลผลใบหน้า: ' + error.message, 'danger');
    }

    if (scanning) requestAnimationFrame(processFrames);
}

function stopFaceScan() {
    scanning = false;
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
    }
    document.getElementById('startScanBtn').style.display = 'inline-block';
    document.getElementById('stopScanBtn').style.display = 'none';
    document.getElementById('scanResult').innerText = 'หยุดการสแกนแล้ว';
    document.getElementById('scanResult').style.color = 'blue';

    const url = 'http://127.0.0.1:5000/stop_scan';
    fetchWithRetry(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    }).catch(error => console.error(`Error stopping scan at ${url}:`, error));

    const courseId = window.currentCourseId;
    const selectedDate = document.getElementById('selectedDate').value;
    if (courseId) {
        fetchAttendance(courseId, selectedDate);
    }
}

function showToast(message, type) {
    const toastContainer = document.createElement('div');
    toastContainer.className = `alert alert-${type} alert-dismissible fade show`;
    toastContainer.style.position = 'fixed';
    toastContainer.style.top = '10px';
    toastContainer.style.right = '10px';
    toastContainer.style.zIndex = '1000';
    toastContainer.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(toastContainer);
    setTimeout(() => toastContainer.remove(), 5000);
}

function getDayNameThai(day) {
    const dayMapping = {
        'Monday': 'วันจันทร์',
        'Tuesday': 'วันอังคาร',
        'Wednesday': 'วันพุธ',
        'Thursday': 'วันพฤหัสบดี',
        'Friday': 'วันศุกร์',
        'Saturday': 'วันเสาร์',
        'Sunday': 'วันอาทิตย์'
    };
    return dayMapping[day] || day;
}

function popupAttendance(result) {
    if (!result.student_id || alertedStudents.has(result.student_id)) return;

    const now = new Date().toLocaleTimeString('th-TH', {
        hour: '2-digit', minute: '2-digit', second: '2-digit'
    });

    const statusColor = 
        result.attendance_text.includes('ขาด') ? 'text-red-600':
        result.attendance_text.includes('มาสาย') ? 'text-yellow-600': 'text-green-600';

    openStatusModel (
    'สแกนสำเร็จ',
        <div> นิสิต: <strong>${result.name}</strong></div>
        <div> รหัสนิสิต: ${result.student_id}</div>
        <div> สถานะ: <span class="${statusColor} font-blod">${result.attendance_text}</span></div>
        <div> เวลา: ${now}</div>
    '5000'
    );
    alertedStudents.add(result.student_id);

    setTimeout(() => alertedStudents.delete(result.student_id),30000);
    
}

window.startFaceScan = startFaceScan;
window.stopFaceScan = stopFaceScan;
window.initializeVideo = initializeVideo;

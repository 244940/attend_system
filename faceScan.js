let video, canvas, ctx, stream;
let scanning = false;
let currentCourseId = null;
let currentScheduleId = null;

function fetchWithRetry(url, options, retries = 3, delay = 1000) {
    return fetch(url, options)
        .then(response => {
            if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
            return response.json();
        })
        .catch(error => {
            if (retries > 0) {
                console.log(`Retrying ${url}... (${retries} attempts left)`);
                return new Promise(resolve => setTimeout(resolve, delay))
                    .then(() => fetchWithRetry(url, options, retries - 1, delay));
            }
            throw error;
        });
}

function initializeVideo() {
    video = document.getElementById('videoFeed');
    canvas = document.getElementById('overlayCanvas');
    ctx = canvas.getContext('2d');

    console.log("Attempting to access webcam...");
    navigator.mediaDevices.getUserMedia({ video: true })
        .then(s => {
            stream = s;
            video.srcObject = stream;
            console.log("Webcam accessed successfully");
            video.onloadedmetadata = () => {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                canvas.style.display = 'block';
                console.log("Video metadata loaded: ", video.videoWidth, video.videoHeight);
                document.getElementById('scanResult').innerText = "กล้องพร้อมใช้งาน";
                document.getElementById('scanResult').style.color = 'green';
            };
        })
        .catch(err => {
            console.error("Error accessing webcam:", err);
            let message = "ไม่สามารถเข้าถึงกล้องได้: " + err.message;
            if (err.name === 'NotFoundError') {
                message += " กรุณาตรวจสอบว่าเชื่อมต่อกล้องแล้ว";
            } else if (err.name === 'NotAllowedError') {
                message += " กรุณาอนุญาตการเข้าถึงกล้องในเบราว์เซอร์";
            }
            document.getElementById('scanResult').innerText = message;
            document.getElementById('scanResult').style.color = 'red';
        });
}

function startFaceScan(teacherId, scheduleId) {
    if (!currentCourseId || !scheduleId) {
        alert("กรุณาเลือกวิชาและตรวจสอบตารางเรียน");
        document.getElementById('scanResult').innerText = "กรุณาเลือกวิชาและตรวจสอบตารางเรียน";
        document.getElementById('scanResult').style.color = 'red';
        return;
    }

    const course = window.courses.find(c => c.course_id == currentCourseId);
    const selectedDate = document.getElementById('selectedDate').value;
    const selectedDay = new Date(selectedDate).toLocaleString('en-US', { weekday: 'long' });

    if (selectedDay !== course.day_of_week) {
        if (!confirm(`วันนี้ (${getDayNameThai(selectedDay)}) ไม่ใช่วันเรียน (${getDayNameThai(course.day_of_week)}). ต้องการดำเนินการสแกนต่อหรือไม่?`)) {
            document.getElementById('scanResult').innerText = "การสแกนถูกยกเลิก";
            document.getElementById('scanResult').style.color = 'red';
            return;
        }
    }

    currentScheduleId = scheduleId;
    console.log("Starting scan with:", { course_id: currentCourseId, teacher_id: teacherId, schedule_id: scheduleId });
    document.getElementById('scanResult').innerText = "กำลังเริ่มการสแกน...";
    document.getElementById('scanResult').style.color = 'black';

    fetchWithRetry('http://localhost:5000/start_scan', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            course_id: currentCourseId,
            teacher_id: teacherId,
            schedule_id: scheduleId
        })
    })
    .then(data => {
        console.log("Scan started:", data);
        if (data.error) throw new Error(data.error);
        scanning = true;
        document.getElementById('startScanBtn').style.display = 'none';
        document.getElementById('stopScanBtn').style.display = 'inline-block';
        document.getElementById('scanResult').innerText = "กำลังสแกนใบหน้า...";
        document.getElementById('scanResult').style.color = 'green';
        processFrames();
    })
    .catch(error => {
        console.error("Fetch error in startFaceScan:", error);
        let message = "ไม่สามารถเริ่มการสแกนได้: " + error.message;
        if (error.message.includes('Failed to fetch')) {
            message += " กรุณาตรวจสอบว่าเซิร์ฟเวอร์ทำงานอยู่ที่ http://localhost:5000 หรือคลิก 'ลองใหม่'";
        }
        document.getElementById('scanResult').innerHTML = `${message} <button onclick="startFaceScan(${teacherId}, ${scheduleId})" class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded">ลองใหม่</button>`;
        document.getElementById('scanResult').style.color = 'red';
    });
}

function stopFaceScan() {
    fetchWithRetry('http://localhost:5000/stop_scan', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(data => {
        console.log("Scan stopped:", data);
        scanning = false;
        document.getElementById('startScanBtn').style.display = 'inline-block';
        document.getElementById('stopScanBtn').style.display = 'none';
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        document.getElementById('scanResult').innerText = "การสแกนหยุดแล้ว";
        document.getElementById('scanResult').style.color = 'blue';
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
    })
    .catch(error => {
        console.error("Fetch error in stopFaceScan:", error);
        document.getElementById('scanResult').innerText = "ไม่สามารถหยุดการสแกนได้: " + error.message;
        document.getElementById('scanResult').style.color = 'red';
    });
}

function processFrames() {
    if (!scanning) return;

    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = video.videoWidth;
    tempCanvas.height = video.videoHeight;
    const tempCtx = tempCanvas.getContext('2d');
    tempCtx.drawImage(video, 0, 0);

    const frameData = tempCanvas.toDataURL('image/jpeg', 0.7);
    fetchWithRetry('http://localhost:5000/process_frame', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            frame: frameData,
            course_id: currentCourseId,
            schedule_id: currentScheduleId
        })
    })
    .then(data => {
        if (data.error) throw new Error(data.error);
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        let lastResult = '';
        data.results.forEach(result => {
            const { top, right, bottom, left } = result.box;
            const scaleX = canvas.width / video.videoWidth;
            const scaleY = canvas.height / video.videoHeight;

            ctx.strokeStyle = 'red';
            ctx.lineWidth = 2;
            ctx.strokeRect(left * scaleX, top * scaleY, (right - left) * scaleX, (bottom - top) * scaleY);

            ctx.fillStyle = 'white';
            ctx.font = '16px Arial';
            ctx.fillText(result.name, (left * scaleX) + 6, (top * scaleY) - 10);
            ctx.fillText(result.attendance_text, (left * scaleX) + 6, (bottom * scaleY) + 20);

            lastResult = `${result.name}: ${result.attendance_text}`;
        });

        document.getElementById('scanResult').innerText = lastResult || "กำลังสแกน...";
        document.getElementById('scanResult').style.color = lastResult ? 'black' : 'green';
        showAttendance(currentCourseId);
        setTimeout(processFrames, 1000);
    })
    .catch(error => {
        console.error("Fetch error in processFrames:", error);
        document.getElementById('scanResult').innerHTML = `ข้อผิดพลาดในการสแกน: ${error.message} <button onclick="startFaceScan(${window.teacherId}, ${currentScheduleId})" class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded">ลองใหม่</button>`;
        document.getElementById('scanResult').style.color = 'red';
        scanning = false;
        document.getElementById('startScanBtn').style.display = 'inline-block';
        document.getElementById('stopScanBtn').style.display = 'none';
    });
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

window.scanning = scanning;
window.stream = stream;
window.startFaceScan = startFaceScan;
window.stopFaceScan = stopFaceScan;
window.initializeVideo = initializeVideo;
window.currentCourseId = currentCourseId;
window.currentScheduleId = currentScheduleId;
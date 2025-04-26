// js/faceScanner.js

let video, canvas, ctx, stream;
let scanning = false;
let currentCourseId = null;

function initializeVideo() {
    video = document.getElementById('videoFeed');
    canvas = document.getElementById('overlayCanvas');
    ctx = canvas.getContext('2d');

    navigator.mediaDevices.getUserMedia({ video: true })
        .then(s => {
            stream = s;
            video.srcObject = stream;
            video.onloadedmetadata = () => {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                canvas.style.display = 'block';
            };
        })
        .catch(err => {
            console.error("Error accessing webcam:", err);
            document.getElementById('scanResult').innerText = "ไม่สามารถเข้าถึงกล้องได้: " + err.message;
            document.getElementById('scanResult').style.color = 'red';
        });
}

function startFaceScan(teacherId) {
    if (!currentCourseId) {
        alert("กรุณาเลือกวิชาก่อนเริ่มสแกน");
        return;
    }

    console.log("Starting scan with course_id:", currentCourseId, "teacher_id:", teacherId);

    fetch('http://localhost:5000/start_scan', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            course_id: currentCourseId,
            teacher_id: teacherId
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.error) throw new Error(data.error);
        console.log("Scan started:", data);
        scanning = true;
        document.getElementById('startScanBtn').style.display = 'none';
        document.getElementById('stopScanBtn').style.display = 'inline-block';
        processFrames();
    })
    .catch(error => {
        console.error("Fetch error in startFaceScan:", error);
        document.getElementById('scanResult').innerText = "ไม่สามารถเริ่มการสแกนได้: " + error.message;
        document.getElementById('scanResult').style.color = 'red';
    });
}

function stopFaceScan() {
    fetch('http://localhost:5000/stop_scan', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        console.log("Scan stopped:", data);
        scanning = false;
        document.getElementById('startScanBtn').style.display = 'inline-block';
        document.getElementById('stopScanBtn').style.display = 'none';
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        document.getElementById('scanResult').innerText = '';
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

    const frameData = tempCanvas.toDataURL('image/jpeg');
    fetch('http://localhost:5000/process_frame', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            frame: frameData,
            course_id: currentCourseId
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! Status: ${response.status}`);
        }
        return response.json();
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

        document.getElementById('scanResult').innerText = lastResult;
        showAttendance(currentCourseId);
        setTimeout(processFrames, 500);
    })
    .catch(error => {
        console.error("Fetch error in processFrames:", error);
        document.getElementById('scanResult').innerText = "ข้อผิดพลาดในการสแกน: " + error.message;
        document.getElementById('scanResult').style.color = 'red';
        scanning = false;
    });
}

// Export variables and functions for use in teacher_dashboard.php
window.scanning = scanning;
window.stream = stream;
window.startFaceScan = startFaceScan;
window.stopFaceScan = stopFaceScan;
window.initializeVideo = initializeVideo;
window.currentCourseId = currentCourseId;
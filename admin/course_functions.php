<?php
// course_functions.php

require 'database_connection.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

function format_time($time) {
    if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        return $time . ":00";
    }
    if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $time)) {
        return $time;
    }
    return false;
}

function validate_semester($semester) {
    $valid_semesters = ['first', 'second', 'summer'];
    return in_array(strtolower($semester), $valid_semesters);
}

function validate_year($year) {
    return is_numeric($year) && strlen($year) === 4 && $year >= 1901 && $year <= 2155;
}

function validate_year_code($year_code) {
    return is_numeric($year_code) && strlen($year_code) === 2 && $year_code >= 00 && $year_code <= 99;
}

function insert_course($conn, $course_data, $schedules) {
    // [Unchanged from previous version]
    $course_name = isset($course_data['course_name']) ? trim($course_data['course_name']) : '';
    $name_en = isset($course_data['name_en']) ? trim($course_data['name_en']) : '';
    $course_code = isset($course_data['course_code']) ? trim($course_data['course_code']) : '';
    $teacher_name = isset($course_data['teacher_name']) ? trim($course_data['teacher_name']) : '';
    $group_number = isset($course_data['group_number']) ? trim($course_data['group_number']) : '';
    $semester = isset($course_data['semester']) ? strtolower(trim($course_data['semester'])) : '';
    $c_year = isset($course_data['c_year']) ? trim($course_data['c_year']) : '';
    $year_code = isset($course_data['year_code']) ? trim($course_data['year_code']) : '';

    if (empty($course_name) || empty($name_en) || empty($course_code) || empty($teacher_name) ||
        empty($group_number) || empty($semester) || empty($c_year) || empty($year_code)) {
        return "Incomplete course data for course: " . ($course_code ?: 'Unknown code');
    }

    if (!validate_semester($semester)) {
        return "Invalid semester for course: $course_code. Must be 'first', 'second', or 'summer'.";
    }

    if (!validate_year($c_year)) {
        return "Invalid year for course: $course_code. Must be between 1901 and 2155.";
    }

    if (!validate_year_code($year_code)) {
        return "Invalid year code for course: $course_code. Must be a 2-digit number (00-99).";
    }

    $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    foreach ($schedules as $index => $schedule) {
        $day_of_week = isset($schedule['day_of_week']) ? trim($schedule['day_of_week']) : '';
        $start_time = isset($schedule['start_time']) ? format_time(trim($schedule['start_time'])) : false;
        $end_time = isset($schedule['end_time']) ? format_time(trim($schedule['end_time'])) : false;

        if (empty($day_of_week) || !$start_time || !$end_time) {
            return "Incomplete schedule data at index $index for course: $course_code";
        }

        if (!in_array($day_of_week, $valid_days)) {
            return "Invalid day of the week at index $index for course: $course_code. Allowed values: " . implode(', ', $valid_days);
        }

        if ($start_time === $end_time) {
            return "Invalid schedule for course $course_code: start_time equals end_time ($start_time) on $day_of_week.";
        }
    }

    $teacher_id = null;
    $teacher_stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE name = ?");
    $teacher_stmt->bind_param("s", $teacher_name);
    $teacher_stmt->execute();
    $teacher_result = $teacher_stmt->get_result();
    if ($teacher_result->num_rows > 0) {
        $teacher_id = $teacher_result->fetch_assoc()['teacher_id'];
        if (!is_numeric($teacher_id) || $teacher_id <= 0) {
            $teacher_stmt->close();
            return "Invalid teacher_id for teacher: $teacher_name";
        }
    } else {
        $teacher_stmt->close();
        return "Teacher not found: $teacher_name";
    }
    $teacher_stmt->close();

    $check_stmt = $conn->prepare(
        "SELECT course_id FROM courses 
         WHERE course_code = ? AND semester = ? AND c_year = ? AND year_code = ? AND group_number = ? AND teacher_name = ?"
    );
    $check_stmt->bind_param("ssssss", $course_code, $semester, $c_year, $year_code, $group_number, $teacher_name);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $check_stmt->close();
        return "SKIP: Course already exists with identical attributes.";
    }
    $check_stmt->close();

    foreach ($schedules as $index => $schedule) {
        $day_of_week = $schedule['day_of_week'];
        $start_time = format_time($schedule['start_time']);
        $end_time = format_time($schedule['end_time']);

        $conflict_stmt = $conn->prepare(
            "SELECT c.course_code, c.group_number 
             FROM schedules s 
             JOIN courses c ON s.course_id = c.course_id 
             WHERE s.teacher_id = ? 
             AND s.day_of_week = ? 
             AND c.semester = ? 
             AND c.c_year = ? 
             AND c.year_code = ?
             AND (
                 (? BETWEEN s.start_time AND s.end_time) 
                 OR (? BETWEEN s.start_time AND s.end_time)
                 OR (s.start_time BETWEEN ? AND ?)
             )"
        );
        $conflict_stmt->bind_param("issssssss", $teacher_id, $day_of_week, $semester, $c_year, $year_code, 
            $start_time, $end_time, $start_time, $end_time);
        $conflict_stmt->execute();
        $conflict_result = $conflict_stmt->get_result();

        if ($conflict_result->num_rows > 0) {
            $conflict = $conflict_result->fetch_assoc();
            $conflict_stmt->close();
            return "Time conflict detected for teacher $teacher_name with course {$conflict['course_code']} group {$conflict['group_number']} on $day_of_week.";
        }
        $conflict_stmt->close();
    }

    $course_stmt = $conn->prepare(
        "INSERT INTO courses 
         (course_name, name_en, course_code, teacher_id, teacher_name, group_number, semester, c_year, year_code) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $course_stmt->bind_param("sssisssss", 
        $course_name, $name_en, $course_code, $teacher_id, $teacher_name, $group_number, $semester, $c_year, $year_code);

    if (!$course_stmt->execute()) {
        $error = "Error adding course $course_code: " . $course_stmt->error;
        $course_stmt->close();
        return $error;
    }

    $course_id = $conn->insert_id;
    $course_stmt->close();

    $schedule_stmt = $conn->prepare(
        "INSERT INTO schedules (course_id, teacher_id, day_of_week, start_time, end_time) 
         VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($schedules as $schedule) {
        $day_of_week = $schedule['day_of_week'];
        $start_time = format_time($schedule['start_time']);
        $end_time = format_time($schedule['end_time']);
        $schedule_stmt->bind_param("iisss", $course_id, $teacher_id, $day_of_week, $start_time, $end_time);

        if (!$schedule_stmt->execute()) {
            $error = "Error adding schedule for course $course_code: " . $schedule_stmt->error;
            $schedule_stmt->close();
            return $error;
        }
    }
    $schedule_stmt->close();

    return null;
}

function update_course($conn, $course_id, $course_data, $schedules) {
    $course_name = isset($course_data['course_name']) ? trim($course_data['course_name']) : '';
    $name_en = isset($course_data['name_en']) ? trim($course_data['name_en']) : '';
    $course_code = isset($course_data['course_code']) ? trim($course_data['course_code']) : '';
    $teacher_name = isset($course_data['teacher_name']) ? trim($course_data['teacher_name']) : '';
    $group_number = isset($course_data['group_number']) ? trim($course_data['group_number']) : '';
    $semester = isset($course_data['semester']) ? strtolower(trim($course_data['semester'])) : '';
    $c_year = isset($course_data['c_year']) ? trim($course_data['c_year']) : '';
    $year_code = isset($course_data['year_code']) ? trim($course_data['year_code']) : '';

    if (empty($course_name) || empty($name_en) || empty($course_code) || empty($teacher_name) ||
        empty($group_number) || empty($semester) || empty($c_year) || empty($year_code)) {
        return "Incomplete course data for course: " . ($course_code ?: 'Unknown code');
    }

    if (!validate_semester($semester)) {
        return "Invalid semester for course: $course_code. Must be 'first', 'second', or 'summer'.";
    }

    if (!validate_year($c_year)) {
        return "Invalid year for course: $course_code. Must be between 1901 and 2155.";
    }

    if (!validate_year_code($year_code)) {
        return "Invalid year code for course: $course_code. Must be a 2-digit number (00-99).";
    }

    $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    foreach ($schedules as $index => $schedule) {
        $day_of_week = isset($schedule['day_of_week']) ? trim($schedule['day_of_week']) : '';
        $start_time = isset($schedule['start_time']) ? format_time(trim($schedule['start_time'])) : false;
        $end_time = isset($schedule['end_time']) ? format_time(trim($schedule['end_time'])) : false;

        if (empty($day_of_week) || !$start_time || !$end_time) {
            return "Incomplete schedule data at index $index for course: $course_code";
        }

        if (!in_array($day_of_week, $valid_days)) {
            return "Invalid day of the week at index $index for course: $course_code. Allowed values: " . implode(', ', $valid_days);
        }

        if ($start_time === $end_time) {
            return "Invalid schedule for course $course_code: start_time equals end_time ($start_time) on $day_of_week.";
        }
    }

    $teacher_id = null;
    $teacher_stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE name = ?");
    $teacher_stmt->bind_param("s", $teacher_name);
    $teacher_stmt->execute();
    $teacher_result = $teacher_stmt->get_result();
    if ($teacher_result->num_rows > 0) {
        $teacher_id = $teacher_result->fetch_assoc()['teacher_id'];
        if (!is_numeric($teacher_id) || $teacher_id <= 0) {
            $teacher_stmt->close();
            return "Invalid teacher_id for teacher: $teacher_name";
        }
    } else {
        $teacher_stmt->close();
        return "Teacher not found: $teacher_name";
    }
    $teacher_stmt->close();

    $check_stmt = $conn->prepare(
        "SELECT course_id FROM courses 
         WHERE course_code = ? AND semester = ? AND c_year = ? AND year_code = ? AND group_number = ? AND teacher_name = ? AND course_id != ?"
    );
    $check_stmt->bind_param("ssssssi", $course_code, $semester, $c_year, $year_code, $group_number, $teacher_name, $course_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $check_stmt->close();
        return "Course already exists with identical attributes.";
    }
    $check_stmt->close();

    foreach ($schedules as $index => $schedule) {
        $day_of_week = $schedule['day_of_week'];
        $start_time = format_time($schedule['start_time']);
        $end_time = format_time($schedule['end_time']);

        $conflict_stmt = $conn->prepare(
            "SELECT c.course_code, c.group_number 
             FROM schedules s 
             JOIN courses c ON s.course_id = c.course_id 
             WHERE s.teacher_id = ? 
             AND s.day_of_week = ? 
             AND c.semester = ? 
             AND c.c_year = ? 
             AND c.year_code = ?
             AND c.course_id != ?
             AND (
                 (? BETWEEN s.start_time AND s.end_time) 
                 OR (? BETWEEN s.start_time AND s.end_time)
                 OR (s.start_time BETWEEN ? AND ?)
             )"
        );
        $conflict_stmt->bind_param("isssssisss", $teacher_id, $day_of_week, $semester, $c_year, $year_code, 
            $course_id, $start_time, $end_time, $start_time, $end_time);
        $conflict_stmt->execute();
        $conflict_result = $conflict_stmt->get_result();

        if ($conflict_result->num_rows > 0) {
            $conflict = $conflict_result->fetch_assoc();
            $conflict_stmt->close();
            return "Time conflict detected for teacher $teacher_name with course {$conflict['course_code']} group {$conflict['group_number']} on $day_of_week.";
        }
        $conflict_stmt->close();
    }

    $conn->begin_transaction();

    try {
        $course_stmt = $conn->prepare(
            "UPDATE courses 
             SET course_name = ?, name_en = ?, course_code = ?, teacher_id = ?, teacher_name = ?, 
                 group_number = ?, semester = ?, c_year = ?, year_code = ?
             WHERE course_id = ?"
        );
        $course_stmt->bind_param(
            "sssisssssi",
            $course_name, $name_en, $course_code, $teacher_id, $teacher_name, 
            $group_number, $semester, $c_year, $year_code, $course_id
        );

        if (!$course_stmt->execute()) {
            $course_stmt->close();
            $conn->rollback();
            return "Error updating course $course_code: " . $course_stmt->error;
        }
        $course_stmt->close();

        $delete_stmt = $conn->prepare("DELETE FROM schedules WHERE course_id = ?");
        $delete_stmt->bind_param("i", $course_id);
        if (!$delete_stmt->execute()) {
            $delete_stmt->close();
            $conn->rollback();
            return "Error deleting existing schedules for course $course_code: " . $delete_stmt->error;
        }
        $delete_stmt->close();

        $schedule_stmt = $conn->prepare(
            "INSERT INTO schedules (course_id, teacher_id, day_of_week, start_time, end_time) 
             VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($schedules as $schedule) {
            $day_of_week = $schedule['day_of_week'];
            $start_time = format_time($schedule['start_time']);
            $end_time = format_time($schedule['end_time']);
            $schedule_stmt->bind_param("iisss", $course_id, $teacher_id, $day_of_week, $start_time, $end_time);

            if (!$schedule_stmt->execute()) {
                $schedule_stmt->close();
                $conn->rollback();
                return "Error adding schedule for course $course_code: " . $schedule_stmt->error;
            }
        }
        $schedule_stmt->close();

        $conn->commit();
        return null;
    } catch (Exception $e) {
        $conn->rollback();
        return "Error updating course $course_code: " . $e->getMessage();
    }
}
?>
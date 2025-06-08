<?php
// course_functions.php

// Ensure this path is correct for your project structure
// require 'database_connection.php'; // Already required in add_course.php before this file
// require 'vendor/autoload.php'; // Already required in add_course.php

// It's generally better if files like add_course.php handle these top-level requires
// and pass $conn to functions, or ensure $conn is globally available.
// For this solution, I'll assume $conn is passed as a parameter as it is in the provided snippet.

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Formats time string to HH:MM:SS or returns false if invalid.
 * @param string $time
 * @return string|false
 */
function format_time($time) {
    if (empty($time)) return false;
    // Try to parse with strtotime to handle various formats and validate
    $timestamp = strtotime($time);
    if ($timestamp === false) {
        return false; // Invalid time format
    }
    // Check if it's a valid time (e.g., not just a date string)
    // This check isn't perfect but helps filter out completely wrong strings
    if (!preg_match('/([0-1]?[0-9]|2[0-3])[:.][0-5][0-9]([:.][0-5][0-9])?/', $time) &&
        !preg_match('/([0-1]?[0-9]|2[0-3])[0-5][0-9]/', $time)) { // for HHMM format (less common for input)
           // if someone inputs a date, strtotime might parse it. This is a basic check.
           if(date('H:i:s', $timestamp) === '00:00:00' && !preg_match('/00[:.]00/',$time)) return false;
    }
    return date('H:i:s', $timestamp); // Format to HH:MM:SS
}


/**
 * Validates semester value.
 * @param string $semester
 * @return bool
 */
function validate_semester($semester) {
    $valid_semesters = ['first', 'second', 'summer'];
    return in_array(strtolower(trim($semester)), $valid_semesters);
}

/**
 * Validates academic year (e.g., 2025).
 * @param string|int $year
 * @return bool
 */
function validate_year($year) {
    return is_numeric($year) && strlen((string)$year) === 4 && $year >= 1900 && $year <= 2155;
}

/**
 * Validates year code (e.g., 60 for 2560).
 * @param string|int $year_code
 * @return bool
 */
function validate_year_code($year_code) {
    $yc = (string)$year_code;
    return is_numeric($year_code) && (strlen($yc) === 2 || strlen($yc) === 1) && (int)$year_code >= 0 && (int)$year_code <= 99;
}


/**
 * Inserts a new course and its schedules into the database.
 *
 * @param mysqli $conn Database connection object.
 * @param array $course_data Associative array of course details.
 * @param array $schedules Array of schedule details (day_of_week, start_time, end_time).
 * @return string|null Null on success, error message string on failure or skip message.
 */
function insert_course($conn, $course_data, $schedules) {
    $course_name = isset($course_data['course_name']) ? trim($course_data['course_name']) : '';
    $name_en = isset($course_data['name_en']) ? trim($course_data['name_en']) : '';
    $course_code = isset($course_data['course_code']) ? trim($course_data['course_code']) : '';
    $teacher_name = isset($course_data['teacher_name']) ? trim($course_data['teacher_name']) : '';
    $group_number = isset($course_data['group_number']) ? trim($course_data['group_number']) : '';
    $semester = isset($course_data['semester']) ? strtolower(trim($course_data['semester'])) : '';
    $c_year = isset($course_data['c_year']) ? trim($course_data['c_year']) : '';
    $year_code = isset($course_data['year_code']) ? trim($course_data['year_code']) : '';

    if (empty($course_name) || empty($name_en) || empty($course_code) || empty($teacher_name) ||
        ($group_number === '' || !is_numeric($group_number)) || // Group number should be numeric
        empty($semester) || empty($c_year) || ($year_code === '' || !is_numeric($year_code)) ) { // year_code should be numeric
        return "Incomplete or invalid course data for course: " . ($course_code ?: 'Unknown code') . ". All fields are required and must be valid.";
    }

    if (!validate_semester($semester)) {
        return "Invalid semester for course $course_code: '$semester'. Must be 'first', 'second', or 'summer'.";
    }
    if (!validate_year($c_year)) {
        return "Invalid year for course $course_code: '$c_year'. Must be a 4-digit year (e.g., 2025).";
    }
    if (!validate_year_code($year_code)) {
        return "Invalid year code for course $course_code: '$year_code'. Must be a 1 or 2-digit number (0-99).";
    }

    if (empty($schedules) || !is_array($schedules)) {
        return "At least one schedule is required for course $course_code.";
    }

    $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    foreach ($schedules as $index => $schedule) {
        $day_of_week = isset($schedule['day_of_week']) ? trim($schedule['day_of_week']) : '';
        $start_time_raw = isset($schedule['start_time']) ? trim($schedule['start_time']) : '';
        $end_time_raw = isset($schedule['end_time']) ? trim($schedule['end_time']) : '';

        $start_time = format_time($start_time_raw);
        $end_time = format_time($end_time_raw);

        if (empty($day_of_week) || $start_time === false || $end_time === false) {
            return "Incomplete or invalid schedule data (Day: '$day_of_week', Start: '$start_time_raw', End: '$end_time_raw') at index $index for course $course_code.";
        }
        if (!in_array($day_of_week, $valid_days)) {
            return "Invalid day of the week '$day_of_week' at index $index for course $course_code. Allowed values: " . implode(', ', $valid_days);
        }
        if (strtotime($start_time) >= strtotime($end_time)) {
            return "Invalid schedule for course $course_code: start time ($start_time) must be before end time ($end_time) on $day_of_week.";
        }
    }

    // Fetch teacher_id
    $teacher_id = null;
    $teacher_stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE name = ?");
    if (!$teacher_stmt) return "DB error (prepare teacher check): " . $conn->error;
    $teacher_stmt->bind_param("s", $teacher_name);
    $teacher_stmt->execute();
    $teacher_result = $teacher_stmt->get_result();
    if ($teacher_row = $teacher_result->fetch_assoc()) {
        $teacher_id = $teacher_row['teacher_id'];
    }
    $teacher_stmt->close();
    if ($teacher_id === null) {
        return "Teacher not found: '$teacher_name'. Please ensure the teacher exists in the system.";
    }

    // Check for duplicate course (same code, semester, c_year, year_code, group, teacher_name)
    $check_stmt = $conn->prepare(
        "SELECT course_id FROM courses 
         WHERE course_code = ? AND semester = ? AND c_year = ? AND year_code = ? AND group_number = ? AND teacher_name = ?"
    );
    if (!$check_stmt) return "DB error (prepare duplicate check): " . $conn->error;
    $check_stmt->bind_param("ssssss", $course_code, $semester, $c_year, $year_code, $group_number, $teacher_name);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows > 0) {
        $check_stmt->close();
        return "SKIP: Course {$course_code} (Group {$group_number}, Sem {$semester}, Year {$c_year}({$year_code})) taught by {$teacher_name} already exists.";
    }
    $check_stmt->close();

    // Check for schedule conflicts for this teacher in the same semester and c_year
    foreach ($schedules as $schedule) {
        $day_of_week = trim($schedule['day_of_week']);
        $start_time = format_time(trim($schedule['start_time'])); // Re-format to be sure
        $end_time = format_time(trim($schedule['end_time']));     // Re-format to be sure

        $conflict_sql = "SELECT c.course_code, c.course_name, c.group_number, 
                                s.start_time AS existing_start_time, s.end_time AS existing_end_time
                         FROM schedules s
                         JOIN courses c ON s.course_id = c.course_id
                         WHERE s.teacher_id = ?       -- Check against the specific teacher_id
                           AND c.semester = ?
                           AND c.c_year = ?           -- Academic year
                           AND s.day_of_week = ?
                           AND s.start_time < ?       -- Existing schedule starts before new one ends
                           AND s.end_time > ?         -- Existing schedule ends after new one starts
                         LIMIT 1";
        
        $conflict_stmt = $conn->prepare($conflict_sql);
        if (!$conflict_stmt) return "DB error (prepare conflict check): " . $conn->error;
        
        // Params: teacher_id, semester, c_year, day_of_week, new_end_time, new_start_time
        $conflict_stmt->bind_param("isssss", $teacher_id, $semester, $c_year, $day_of_week, $end_time, $start_time);
        $conflict_stmt->execute();
        $conflict_result = $conflict_stmt->get_result();

        if ($conflict_row = $conflict_result->fetch_assoc()) {
            $conflict_stmt->close();
            return "Schedule conflict for teacher '$teacher_name' on $day_of_week ($start_time - $end_time). " .
                   "Conflicts with existing course: {$conflict_row['course_code']} \"{$conflict_row['course_name']}\" (Group {$conflict_row['group_number']}) " .
                   "scheduled from " . date("H:i", strtotime($conflict_row['existing_start_time'])) . " to " . date("H:i", strtotime($conflict_row['existing_end_time'])) . ".";
        }
        $conflict_stmt->close();
    }

    // All checks passed, proceed with transaction
    $conn->begin_transaction();
    try {
        $course_insert_stmt = $conn->prepare(
            "INSERT INTO courses 
             (course_name, name_en, course_code, teacher_id, teacher_name, group_number, semester, c_year, year_code) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$course_insert_stmt) throw new Exception("DB error (prepare course insert): " . $conn->error);
        
        $course_insert_stmt->bind_param("sssisssss", 
            $course_name, $name_en, $course_code, $teacher_id, $teacher_name, 
            $group_number, $semester, $c_year, $year_code
        );

        if (!$course_insert_stmt->execute()) {
            throw new Exception("Error adding course $course_code to 'courses' table: " . $course_insert_stmt->error);
        }
        $new_course_id = $conn->insert_id;
        $course_insert_stmt->close();

        if (!$new_course_id) {
             throw new Exception("Failed to get course_id for new course $course_code.");
        }

        $schedule_insert_stmt = $conn->prepare(
            "INSERT INTO schedules (course_id, teacher_id, day_of_week, start_time, end_time) 
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$schedule_insert_stmt) throw new Exception("DB error (prepare schedule insert): " . $conn->error);

        foreach ($schedules as $schedule) {
            $day_of_week = trim($schedule['day_of_week']);
            $start_time = format_time(trim($schedule['start_time']));
            $end_time = format_time(trim($schedule['end_time']));
            
            $schedule_insert_stmt->bind_param("iisss", $new_course_id, $teacher_id, $day_of_week, $start_time, $end_time);
            if (!$schedule_insert_stmt->execute()) {
                throw new Exception("Error adding schedule ($day_of_week $start_time-$end_time) for course $course_code: " . $schedule_insert_stmt->error);
            }
        }
        $schedule_insert_stmt->close();

        $conn->commit();
        return null; // Success

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Transaction failed in insert_course: " . $e->getMessage());
        return "An error occurred while adding the course: " . $e->getMessage();
    }
}


/**
 * Updates an existing course and its schedules.
 *
 * @param mysqli $conn Database connection object.
 * @param int $course_id The ID of the course to update.
 * @param array $course_data Associative array of course details.
 * @param array $schedules Array of new schedule details.
 * @return string|null Null on success, error message string on failure.
 */
function update_course($conn, $course_id, $course_data, $schedules) {
    // Basic validation for course_id
    if (!is_numeric($course_id) || $course_id <= 0) {
        return "Invalid Course ID for update.";
    }

    $course_name = isset($course_data['course_name']) ? trim($course_data['course_name']) : '';
    $name_en = isset($course_data['name_en']) ? trim($course_data['name_en']) : '';
    $course_code = isset($course_data['course_code']) ? trim($course_data['course_code']) : '';
    $teacher_name = isset($course_data['teacher_name']) ? trim($course_data['teacher_name']) : '';
    $group_number = isset($course_data['group_number']) ? trim($course_data['group_number']) : '';
    $semester = isset($course_data['semester']) ? strtolower(trim($course_data['semester'])) : '';
    $c_year = isset($course_data['c_year']) ? trim($course_data['c_year']) : '';
    $year_code = isset($course_data['year_code']) ? trim($course_data['year_code']) : '';

    if (empty($course_name) || empty($name_en) || empty($course_code) || empty($teacher_name) ||
        ($group_number === '' || !is_numeric($group_number)) ||
        empty($semester) || empty($c_year) || ($year_code === '' || !is_numeric($year_code)) ) {
        return "Incomplete or invalid course data for update (Course ID: $course_id). All fields are required and must be valid.";
    }
    if (!validate_semester($semester)) {
        return "Invalid semester for course $course_code (ID: $course_id): '$semester'.";
    }
    if (!validate_year($c_year)) {
        return "Invalid year for course $course_code (ID: $course_id): '$c_year'.";
    }
    if (!validate_year_code($year_code)) {
        return "Invalid year code for course $course_code (ID: $course_id): '$year_code'.";
    }
    if (empty($schedules) || !is_array($schedules)) {
        return "At least one schedule is required for course $course_code (ID: $course_id).";
    }

    $valid_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    foreach ($schedules as $index => $schedule) {
        $day_of_week = isset($schedule['day_of_week']) ? trim($schedule['day_of_week']) : '';
        $start_time_raw = isset($schedule['start_time']) ? trim($schedule['start_time']) : '';
        $end_time_raw = isset($schedule['end_time']) ? trim($schedule['end_time']) : '';
        
        $start_time = format_time($start_time_raw);
        $end_time = format_time($end_time_raw);

        if (empty($day_of_week) || $start_time === false || $end_time === false) {
            return "Incomplete or invalid schedule data (Day: '$day_of_week', Start: '$start_time_raw', End: '$end_time_raw') at index $index for course $course_code (ID: $course_id).";
        }
        if (!in_array($day_of_week, $valid_days)) {
            return "Invalid day of the week '$day_of_week' at index $index for course $course_code (ID: $course_id).";
        }
        if (strtotime($start_time) >= strtotime($end_time)) {
            return "Invalid schedule for course $course_code (ID: $course_id): start time ($start_time) must be before end time ($end_time) on $day_of_week.";
        }
    }

    // Fetch teacher_id
    $teacher_id = null;
    $teacher_stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE name = ?");
    if (!$teacher_stmt) return "DB error (prepare teacher check): " . $conn->error;
    $teacher_stmt->bind_param("s", $teacher_name);
    $teacher_stmt->execute();
    $teacher_result = $teacher_stmt->get_result();
    if ($teacher_row = $teacher_result->fetch_assoc()) {
        $teacher_id = $teacher_row['teacher_id'];
    }
    $teacher_stmt->close();
    if ($teacher_id === null) {
        return "Teacher not found for update: '$teacher_name'.";
    }

    // Check for duplicate course (excluding current course_id)
    $check_stmt = $conn->prepare(
        "SELECT course_id FROM courses 
         WHERE course_code = ? AND semester = ? AND c_year = ? AND year_code = ? AND group_number = ? AND teacher_name = ?
         AND course_id != ?"
    );
    if (!$check_stmt) return "DB error (prepare duplicate check for update): " . $conn->error;
    $check_stmt->bind_param("ssssssi", $course_code, $semester, $c_year, $year_code, $group_number, $teacher_name, $course_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    if ($check_result->num_rows > 0) {
        $check_stmt->close();
        return "Another course already exists with identical attributes (Code: $course_code, Sem: $semester, Year: $c_year($year_code), Group: $group_number, Teacher: $teacher_name).";
    }
    $check_stmt->close();

    // Check for schedule conflicts (excluding current course_id's own schedules)
    foreach ($schedules as $schedule) {
        $day_of_week = trim($schedule['day_of_week']);
        $start_time = format_time(trim($schedule['start_time']));
        $end_time = format_time(trim($schedule['end_time']));

        $conflict_sql = "SELECT c.course_code, c.course_name, c.group_number, 
                                s.start_time AS existing_start_time, s.end_time AS existing_end_time
                         FROM schedules s
                         JOIN courses c ON s.course_id = c.course_id
                         WHERE s.teacher_id = ?
                           AND c.semester = ?
                           AND c.c_year = ?
                           AND s.day_of_week = ?
                           AND s.start_time < ?  -- Existing starts before new ends
                           AND s.end_time > ?    -- Existing ends after new starts
                           AND c.course_id != ?  -- Exclude the course being updated
                         LIMIT 1";
        
        $conflict_stmt = $conn->prepare($conflict_sql);
        if (!$conflict_stmt) return "DB error (prepare conflict check for update): " . $conn->error;
        
        // Params: teacher_id, semester, c_year, day_of_week, new_end_time, new_start_time, current_course_id
        $conflict_stmt->bind_param("isssssi", $teacher_id, $semester, $c_year, $day_of_week, $end_time, $start_time, $course_id);
        $conflict_stmt->execute();
        $conflict_result = $conflict_stmt->get_result();

        if ($conflict_row = $conflict_result->fetch_assoc()) {
            $conflict_stmt->close();
            return "Schedule conflict for teacher '$teacher_name' on $day_of_week ($start_time - $end_time) when updating course $course_code (ID: $course_id). " .
                   "Conflicts with existing course: {$conflict_row['course_code']} \"{$conflict_row['course_name']}\" (Group {$conflict_row['group_number']}) " .
                   "scheduled from " . date("H:i", strtotime($conflict_row['existing_start_time'])) . " to " . date("H:i", strtotime($conflict_row['existing_end_time'])) . ".";
        }
        $conflict_stmt->close();
    }

    // All checks passed, proceed with transaction
    $conn->begin_transaction();
    try {
        $course_update_stmt = $conn->prepare(
            "UPDATE courses 
             SET course_name = ?, name_en = ?, course_code = ?, teacher_id = ?, teacher_name = ?, 
                 group_number = ?, semester = ?, c_year = ?, year_code = ?
             WHERE course_id = ?"
        );
        if (!$course_update_stmt) throw new Exception("DB error (prepare course update): " . $conn->error);
        
        $course_update_stmt->bind_param("sssisssssi",
            $course_name, $name_en, $course_code, $teacher_id, $teacher_name, 
            $group_number, $semester, $c_year, $year_code, $course_id
        );
        if (!$course_update_stmt->execute()) {
            throw new Exception("Error updating course $course_code (ID: $course_id): " . $course_update_stmt->error);
        }
        $course_update_stmt->close();

        // Delete existing schedules for this course
        $delete_schedules_stmt = $conn->prepare("DELETE FROM schedules WHERE course_id = ?");
        if (!$delete_schedules_stmt) throw new Exception("DB error (prepare delete schedules): " . $conn->error);
        $delete_schedules_stmt->bind_param("i", $course_id);
        if (!$delete_schedules_stmt->execute()) {
            throw new Exception("Error deleting existing schedules for course $course_code (ID: $course_id): " . $delete_schedules_stmt->error);
        }
        $delete_schedules_stmt->close();

        // Insert new schedules
        $schedule_insert_stmt = $conn->prepare(
            "INSERT INTO schedules (course_id, teacher_id, day_of_week, start_time, end_time) 
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$schedule_insert_stmt) throw new Exception("DB error (prepare schedule insert for update): " . $conn->error);

        foreach ($schedules as $schedule) {
            $day_of_week = trim($schedule['day_of_week']);
            $start_time = format_time(trim($schedule['start_time']));
            $end_time = format_time(trim($schedule['end_time']));
            
            $schedule_insert_stmt->bind_param("iisss", $course_id, $teacher_id, $day_of_week, $start_time, $end_time);
            if (!$schedule_insert_stmt->execute()) {
                throw new Exception("Error adding new schedule ($day_of_week $start_time-$end_time) for course $course_code (ID: $course_id): " . $schedule_insert_stmt->error);
            }
        }
        $schedule_insert_stmt->close();

        $conn->commit();
        return null; // Success

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Transaction failed in update_course: " . $e->getMessage());
        return "An error occurred while updating the course: " . $e->getMessage();
    }
}

?>
-- Back up attendance_system before applying this migration.
-- Safe to run more than once on the current MariaDB version bundled with XAMPP.

ALTER TABLE students
    ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NULL AFTER email,
    ADD COLUMN IF NOT EXISTS status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active';

ALTER TABLE lecturers
    ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NULL AFTER email,
    ADD COLUMN IF NOT EXISTS status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    ADD COLUMN IF NOT EXISTS user_id INT NULL;

ALTER TABLE courses
    ADD COLUMN IF NOT EXISTS description TEXT NULL,
    ADD COLUMN IF NOT EXISTS semester VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active';

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS student_courses (
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    enrolled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, course_id),
    KEY idx_student_courses_course (course_id),
    CONSTRAINT fk_student_courses_student
        FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
    CONSTRAINT fk_student_courses_course
        FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve legacy free-text course values as courses and formal enrollments.
INSERT IGNORE INTO courses (course_code, course_name, status)
SELECT CONCAT('LEGACY-', UPPER(SUBSTRING(MD5(TRIM(course)), 1, 10))),
       TRIM(course),
       'Active'
FROM students
WHERE course IS NOT NULL AND TRIM(course) <> ''
GROUP BY TRIM(course);

INSERT IGNORE INTO student_courses (student_id, course_id, enrolled_at)
SELECT s.id, c.id, s.created_at
FROM students s
INNER JOIN courses c
    ON c.course_code = CONCAT('LEGACY-', UPPER(SUBSTRING(MD5(TRIM(s.course)), 1, 10)))
WHERE s.course IS NOT NULL AND TRIM(s.course) <> '';

-- Retain historical attendance as an enrollment and enforce this relationship
-- for all attendance records going forward.
INSERT INTO student_courses (student_id, course_id, enrolled_at)
SELECT student_id, course_id, MIN(attendance_date)
FROM attendance
GROUP BY student_id, course_id
ON DUPLICATE KEY UPDATE
    enrolled_at = LEAST(student_courses.enrolled_at, VALUES(enrolled_at));

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id INT NOT NULL,
    action VARCHAR(30) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_created_at (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link lecturers to optional login accounts without changing existing accounts.
SET @has_lecturer_user_fk = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'lecturers'
      AND COLUMN_NAME = 'user_id'
      AND REFERENCED_TABLE_NAME = 'users'
);
SET @add_lecturer_user_fk = IF(
    @has_lecturer_user_fk = 0,
    'ALTER TABLE lecturers ADD CONSTRAINT fk_lecturers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE lecturer_fk_statement FROM @add_lecturer_user_fk;
EXECUTE lecturer_fk_statement;
DEALLOCATE PREPARE lecturer_fk_statement;

SET @has_lecturer_user_unique = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'lecturers'
      AND COLUMN_NAME = 'user_id'
      AND NON_UNIQUE = 0
);
SET @add_lecturer_user_unique = IF(
    @has_lecturer_user_unique = 0,
    'ALTER TABLE lecturers ADD UNIQUE INDEX uq_lecturers_user_id (user_id)',
    'SELECT 1'
);
PREPARE lecturer_unique_statement FROM @add_lecturer_user_unique;
EXECUTE lecturer_unique_statement;
DEALLOCATE PREPARE lecturer_unique_statement;

SET @has_attendance_enrollment_fk = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'attendance'
      AND CONSTRAINT_NAME = 'fk_attendance_enrollment'
);
SET @add_attendance_enrollment_fk = IF(
    @has_attendance_enrollment_fk = 0,
    'ALTER TABLE attendance ADD CONSTRAINT fk_attendance_enrollment FOREIGN KEY (student_id, course_id) REFERENCES student_courses (student_id, course_id) ON DELETE RESTRICT ON UPDATE RESTRICT',
    'SELECT 1'
);
PREPARE attendance_enrollment_fk_statement FROM @add_attendance_enrollment_fk;
EXECUTE attendance_enrollment_fk_statement;
DEALLOCATE PREPARE attendance_enrollment_fk_statement;

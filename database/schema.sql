-- SORERS schema. Written to run on MySQL (InnoDB) and, for local testing, SQLite.

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTO_INCREMENT,
    student_no    VARCHAR(30)  NULL UNIQUE,
    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'student',   -- student|officer|adviser|staff|admin
    course        VARCHAR(60)  NULL,
    is_active     INTEGER      NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE organizations (
    id           INTEGER PRIMARY KEY AUTO_INCREMENT,
    name         VARCHAR(150) NOT NULL UNIQUE,
    description  TEXT         NULL,
    course_scope VARCHAR(100) NULL,                          -- e.g. "BSIT" or "All courses"
    adviser_id   INTEGER      NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (adviser_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE memberships (
    id         INTEGER PRIMARY KEY AUTO_INCREMENT,
    org_id     INTEGER     NOT NULL,
    user_id    INTEGER     NOT NULL,
    position   VARCHAR(60) NOT NULL DEFAULT 'Member',
    is_officer INTEGER     NOT NULL DEFAULT 0,
    status     VARCHAR(10) NOT NULL DEFAULT 'active',        -- active|pending
    joined_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (org_id, user_id),
    FOREIGN KEY (org_id)  REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)         ON DELETE CASCADE
);

CREATE TABLE events (
    id            INTEGER PRIMARY KEY AUTO_INCREMENT,
    org_id        INTEGER      NOT NULL,
    title         VARCHAR(200) NOT NULL,
    description   TEXT         NULL,
    venue         VARCHAR(150) NOT NULL,
    start_at      DATETIME     NOT NULL,
    end_at        DATETIME     NOT NULL,
    reg_deadline  DATETIME     NOT NULL,
    capacity      INTEGER      NULL,                         -- NULL = no limit
    status        VARCHAR(12)  NOT NULL DEFAULT 'draft',     -- draft|published|cancelled
    created_by    INTEGER      NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (org_id)     REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)         ON DELETE SET NULL
);

CREATE TABLE registrations (
    id         INTEGER PRIMARY KEY AUTO_INCREMENT,
    event_id   INTEGER     NOT NULL,
    user_id    INTEGER     NOT NULL,
    status     VARCHAR(12) NOT NULL DEFAULT 'registered',    -- registered|cancelled
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (event_id, user_id),                              -- blocks duplicate entries
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE
);

CREATE TABLE attendance (
    id              INTEGER PRIMARY KEY AUTO_INCREMENT,
    registration_id INTEGER     NOT NULL UNIQUE,
    status          VARCHAR(10) NOT NULL,                    -- present|absent
    marked_by       INTEGER     NULL,
    marked_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    FOREIGN KEY (marked_by)       REFERENCES users(id)         ON DELETE SET NULL
);

# Migration Architecture

This document explains the architecture behind the **Large Scale Data
Migration Framework**.

The system was designed to safely process **millions of records in a
live production environment** without disrupting normal application
activity.

------------------------------------------------------------------------

## Design Goals

The migration framework was built with the following goals:

-   **Safety in production environments**
-   **Ability to process very large datasets**
-   **Restart capability after interruption**
-   **Minimal database load**
-   **Clear monitoring of migration progress**

------------------------------------------------------------------------

## Core Components

### 1. Migration Controller

The main migration script coordinates the overall process. It controls
batch execution, handles progress tracking, and ensures the migration
proceeds sequentially through the dataset.

Responsibilities:

-   start migration loop
-   load next batch of records
-   trigger processing logic
-   track progress

------------------------------------------------------------------------

### 2. Batch Processing Engine

Instead of processing the entire dataset at once, records are processed
in **small batches**.

This prevents:

-   long database locks
-   server overload
-   timeouts in long-running queries

Typical batch workflow:

1.  Query next batch of records
2.  Process records in memory
3.  Update calculated results
4.  Store progress state

------------------------------------------------------------------------

### 3. Progress Tracking

The migration stores the **ID of the last processed record**.

This enables:

-   restart after crash
-   restart after timeout
-   controlled incremental execution

Example state:

last_processed_id = 2459201

When the script restarts, it continues from this position.

------------------------------------------------------------------------

### 4. Monitoring Output

The migration prints runtime information such as:

-   processed record counts
-   current batch progress
-   last processed ID
-   estimated remaining workload

This allows operators to monitor long-running migrations.

------------------------------------------------------------------------

### 5. Process Monitoring & Automatic Restart

Long-running migrations in web environments can occasionally stall due to
timeouts, resource limits, or unexpected runtime conditions.

To reduce the need for manual supervision, the migration script includes a
lightweight monitoring mechanism implemented with a combination of PHP and
JavaScript.

The migration runs inside a child iframe, while a parent page monitors the
progress output produced by the script.

The migration script continuously prints progress indicators (dots and status
messages). The parent monitoring script periodically checks whether the output
inside the iframe is still growing.

Monitoring logic:

1. The parent page measures the length of the iframe output.
2. If the output length increases, the migration is progressing normally.
3. If the output does not change for a defined time window, the process is
   assumed to be stuck.
4. The parent page automatically reloads the iframe, restarting the migration
   process.

Because the migration tracks the ID of the last processed record, the process
can safely resume from the last completed batch after a restart.

This approach provides a simple self-healing mechanism that allows large
migrations to run unattended for extended periods without blocking progress.

This makes the migration **idempotent and safe for repeated execution**.

-----------------------------------------------------------------------

## Simplified Data Flow

Game Database

↓

Migration Controller

↓

Batch Processing

↓

Score Calculation

↓

Database Update

↓

Progress Stored

------------------------------------------------------------------------

## Operational Characteristics

The system was designed to operate in a **live production environment**,
meaning:

-   gameplay services remained available
-   database load stayed controlled
-   partial migrations were avoided
-   the process could run unattended for extended periods

This approach allowed the migration of millions of records across
multiple database environments over several days without downtime.

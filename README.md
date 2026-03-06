# Data Migration Framework

Example architecture of a restartable data migration pipeline designed for processing millions of records safely in production environments.

The framework processes data in controlled batches, tracks progress, and can automatically recover from failures without restarting the entire migration.

---

## Real World Context

This code demonstrates the architecture used for a production migration where historical combat statistics had to be recalculated from existing gameplay logs.

The dataset contained **millions of records**, so the migration had to be:

- safe for production servers
- restartable in case of timeouts or crashes
- able to run in small batches to avoid database overload
- capable of monitoring progress across multiple environments

---

## Architecture Overview

Game Database
↓
Migration Script
↓
Batch Processing Engine
↓
Progress Tracking
↓
Automatic Restart Logic
↓
Monitoring Output


## Key Design Principles

**Batch Processing**

Large datasets are processed in small batches to prevent excessive database load.

**Restartable Execution**

The script stores the ID of the last processed record so migrations can safely resume after interruption.

**Production Safety**

The system is designed to run against live systems without blocking gameplay operations.

**Monitoring & Transparency**

Progress output allows operators to track migration progress and detect stalls or failures.


## Project Structure


## Technologies

- PHP
- MySQL
- Batch data processing
- Transactional updates
- Progress monitoring
---

## Author

Veronika Pavlisin
Senior Backend Engineer

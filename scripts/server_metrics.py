import sqlite3
import mysql.connector
import psutil
import time
import json
import logging
import os
from datetime import datetime

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

# int-updater.sh sets DB_CONNECTION=mysql (docker-compose.yml's CORE_DB_*
# overrides) only for a host it found already running core-db -- everyone
# else, sqlite is the default. Both backends' drivers are always installed
# (Dockerfile-metrics), so which one runs is a runtime decision, not a build
# one.
def is_mysql():
    return os.getenv("DB_CONNECTION", "sqlite") == "mysql"

# Same tuning as core/config/database.php's sqlite connection: WAL lets core
# and this daemon write concurrently, busy_timeout makes SQLite retry a locked
# database internally before raising, instead of failing the write outright.
def connect_to_db_sqlite(path):
    try:
        connection = sqlite3.connect(path, timeout=5)
        connection.execute("PRAGMA journal_mode=WAL")
        connection.execute("PRAGMA synchronous=NORMAL")
        connection.execute("PRAGMA busy_timeout=5000")
        logging.info("Connected to database successfully.")
        return connection
    except sqlite3.Error as e:
        logging.error(f"Database connection error: {e}")
    return None

def connect_to_db_mysql(config):
    try:
        connection = mysql.connector.connect(**config)
        if connection.is_connected():
            logging.info("Connected to database successfully.")
            return connection
    except mysql.connector.Error as e:
        logging.error(f"Database connection error: {e}")
    return None

def collect_metrics(prev_metrics):
    try:
        now = time.time()
        elapsed = now - prev_metrics.get("timestamp", now)
        if elapsed == 0:
            elapsed = 1  # avoid division by zero

        cpu = psutil.cpu_percent(interval=None)
        load_avg = psutil.getloadavg()

        disk_io = psutil.disk_io_counters()
        disk_read_Bps = (disk_io.read_bytes - prev_metrics.get("disk_read_bytes", 0)) / elapsed
        disk_write_Bps = (disk_io.write_bytes - prev_metrics.get("disk_write_bytes", 0)) / elapsed
        disk_read_iops = (disk_io.read_count - prev_metrics.get("disk_read_count", 0)) / elapsed
        disk_write_iops = (disk_io.write_count - prev_metrics.get("disk_write_count", 0)) / elapsed

        net_io = psutil.net_io_counters()
        net_in_Bps = (net_io.bytes_recv - prev_metrics.get("net_in_bytes", 0)) / elapsed
        net_out_Bps = (net_io.bytes_sent - prev_metrics.get("net_out_bytes", 0)) / elapsed
        net_in_pps = (net_io.packets_recv - prev_metrics.get("net_in_pkts", 0)) / elapsed
        net_out_pps = (net_io.packets_sent - prev_metrics.get("net_out_pkts", 0)) / elapsed

        prev_metrics.update({
            "timestamp": now,
            "disk_read_bytes": disk_io.read_bytes,
            "disk_write_bytes": disk_io.write_bytes,
            "disk_read_count": disk_io.read_count,
            "disk_write_count": disk_io.write_count,
            "net_in_bytes": net_io.bytes_recv,
            "net_out_bytes": net_io.bytes_sent,
            "net_in_pkts": net_io.packets_recv,
            "net_out_pkts": net_io.packets_sent,
        })

        return {
            "timestamp": datetime.fromtimestamp(now).strftime("%Y-%m-%d %H:%M:%S"),
            "cpu_percent": cpu,
            "cpu_load_avg_1": load_avg[0],
            "cpu_load_avg_5": load_avg[1],
            "cpu_load_avg_15": load_avg[2],
            "ram_percent": psutil.virtual_memory().percent,
            "swap_percent": psutil.swap_memory().percent,
            "disk_read_bps": disk_read_Bps,
            "disk_write_bps": disk_write_Bps,
            "disk_read_iops": disk_read_iops,
            "disk_write_iops": disk_write_iops,
            "net_in_bps": net_in_Bps,
            "net_out_bps": net_out_Bps,
            "net_in_pps": net_in_pps,
            "net_out_pps": net_out_pps,
        }

    except Exception as e:
        logging.error(f"Failed to collect system metrics: {e}")
        return None

def get_interval(default=3, min_value=3):
    interval = os.getenv("METRICS_SNAPSHOT_INTERVAL")
    if interval is not None:
        try:
            interval = int(interval)
            if interval < min_value:
                print(f"Warning: Interval is less than minimum value. Using default: {default}")
                return default
            return interval
        except ValueError:
            print("Warning: METRICS_SNAPSHOT_INTERVAL must be an integer. Using default value.")
    return default

# core's entrypoint creates the file before chowning storage, but this
# container has no entrypoint of its own and may start first -- wait rather
# than crash-loop on a path that appears moments later.
def wait_for_db_file(path, timeout=60):
    waited = 0
    while not os.path.exists(path):
        if waited >= timeout:
            return False
        logging.info(f"Waiting for {path} to exist...")
        time.sleep(2)
        waited += 2
    return True

def main():
    mysql_mode = is_mysql()

    if mysql_mode:
        db_config = {
            "user": os.getenv("DB_USERNAME"),
            "password": os.getenv("DB_PASSWORD"),
            "host": "127.0.0.1",
            "database": os.getenv("DB_DATABASE"),
            "port": 3306,
        }
        if not all(db_config.values()):
            logging.error("One or more required environment variables are missing.")
            return
        connection = connect_to_db_mysql(db_config)
    else:
        db_path = os.getenv("DB_DATABASE")
        if not db_path:
            logging.error("DB_DATABASE environment variable is missing.")
            return
        if not wait_for_db_file(db_path):
            logging.error(f"{db_path} never appeared; giving up.")
            return
        connection = connect_to_db_sqlite(db_path)

    if not connection:
        return

    cursor = connection.cursor()

    disk_io = psutil.disk_io_counters()
    net_io = psutil.net_io_counters()
    prev_metrics = {
        "timestamp": time.time(),
        "disk_read_bytes": disk_io.read_bytes,
        "disk_write_bytes": disk_io.write_bytes,
        "disk_read_count": disk_io.read_count,
        "disk_write_count": disk_io.write_count,
        "net_in_bytes": net_io.bytes_recv,
        "net_out_bytes": net_io.bytes_sent,
        "net_in_pkts": net_io.packets_recv,
        "net_out_pkts": net_io.packets_sent,
    }

    interval = get_interval()
    placeholder = "%s" if mysql_mode else "?"
    values_clause = ", ".join([placeholder] * 15)

    try:
        while True:
            time.sleep(interval)
            metrics = collect_metrics(prev_metrics)
            if not metrics:
                logging.error("Skipping insertion due to failed metrics collection.")
                time.sleep(1)
                continue
            query = f"""
                INSERT INTO server_metrics (
                    timestamp,
                    cpu_percent,
                    cpu_load_avg_1,
                    cpu_load_avg_5,
                    cpu_load_avg_15,
                    ram_percent,
                    swap_percent,
                    disk_read_bps,
                    disk_write_bps,
                    disk_read_iops,
                    disk_write_iops,
                    net_in_bps,
                    net_out_bps,
                    net_in_pps,
                    net_out_pps
                )
                VALUES ({values_clause})
            """
            params = (
                metrics["timestamp"],
                metrics["cpu_percent"],
                metrics["cpu_load_avg_1"],
                metrics["cpu_load_avg_5"],
                metrics["cpu_load_avg_15"],
                metrics["ram_percent"],
                metrics["swap_percent"],
                metrics["disk_read_bps"],
                metrics["disk_write_bps"],
                metrics["disk_read_iops"],
                metrics["disk_write_iops"],
                metrics["net_in_bps"],
                metrics["net_out_bps"],
                metrics["net_in_pps"],
                metrics["net_out_pps"],
            )

            # A locked database (core mid-write under WAL) is transient --
            # busy_timeout already waits inside sqlite3, so a retry here only
            # covers the rare case that outlasts it. mysql.connector has no
            # equivalent lock here (InnoDB queues writers itself), so it
            # keeps the original one-shot behavior.
            attempts = 0
            while True:
                try:
                    cursor.execute(query, params)
                    connection.commit()
                    # logging.info("Metrics inserted successfully.")
                    break
                except (sqlite3.OperationalError, mysql.connector.Error) as e:
                    attempts += 1
                    if isinstance(e, sqlite3.OperationalError) and "locked" in str(e).lower() and attempts < 3:
                        time.sleep(1)
                        continue
                    logging.error(f"Failed to insert metrics: {e}")
                    break

    except KeyboardInterrupt:
        logging.info("Terminating script.")
    except Exception as e:
        logging.error(f"Unexpected error: {e}")
    finally:
        cursor.close()
        if mysql_mode:
            if connection.is_connected():
                connection.close()
        else:
            connection.close()
        logging.info("Database connection closed.")

if __name__ == "__main__":
    main()

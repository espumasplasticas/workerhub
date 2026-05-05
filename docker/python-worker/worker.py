import json
import os
import time
from typing import Any

import requests
from kafka import KafkaConsumer


def env(name: str, default: str = "") -> str:
    value = os.getenv(name, default)
    return value.strip() if isinstance(value, str) else default


BOOTSTRAP_SERVERS = env("KAFKA_BOOTSTRAP_SERVERS", "redpanda:9092")
TOPIC = env("KAFKA_TOPIC", "workerhub.runtime.python.customers")
CONSUMER_GROUP = env("KAFKA_CONSUMER_GROUP", "workerhub-python-customers")
CALLBACK_BASE_URL = env("WORKERHUB_CALLBACK_BASE_URL", "http://workerhub-web")
RUNTIME_SHARED_TOKEN = env("WORKERHUB_RUNTIME_SHARED_TOKEN")
POLL_INTERVAL = float(env("WORKERHUB_PYTHON_POLL_INTERVAL", "1"))


def callback(task_id: str, status: str, message: str = "", result: dict[str, Any] | None = None) -> None:
    if not task_id or not RUNTIME_SHARED_TOKEN:
        return

    response = requests.post(
        f"{CALLBACK_BASE_URL.rstrip('/')}/api/internal/tasks/{task_id}/status",
        headers={
            "X-WorkerHub-Shared-Token": RUNTIME_SHARED_TOKEN,
            "Content-Type": "application/json",
        },
        json={
            "status": status,
            "message": message,
            "result": result or {},
            "context": {
                "runtime": "python",
                "topic": TOPIC,
            },
        },
        timeout=10,
    )
    response.raise_for_status()


def process_message(task: dict[str, Any]) -> None:
    task_id = str(task.get("task_id", "")).strip()
    process_key = str(task.get("process_key") or task.get("execution_plan", {}).get("process_key") or "general")
    doc_id = str(task.get("document_id") or task.get("payload", {}).get("document_id") or "")

    callback(task_id, "queued", "Python worker accepted task.")
    callback(task_id, "processing", "Python worker started processing.")

    time.sleep(POLL_INTERVAL)

    callback(
        task_id,
        "completed",
        "Python worker completed the task.",
        {
            "runtime": "python",
            "process_key": process_key,
            "document_id": doc_id,
            "message": "Base Python worker executed successfully.",
        },
    )


def main() -> None:
    consumer = KafkaConsumer(
        TOPIC,
        bootstrap_servers=[server.strip() for server in BOOTSTRAP_SERVERS.split(",") if server.strip()],
        group_id=CONSUMER_GROUP,
        enable_auto_commit=True,
        value_deserializer=lambda value: json.loads(value.decode("utf-8")),
        auto_offset_reset="latest",
    )

    for message in consumer:
        task = message.value if isinstance(message.value, dict) else {}

        try:
            process_message(task)
        except Exception as exc:  # pragma: no cover
            task_id = str(task.get("task_id", "")).strip()
            callback(task_id, "failed", f"Python worker failed: {exc}")


if __name__ == "__main__":
    main()

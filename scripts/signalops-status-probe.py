#!/usr/bin/env python3
"""Restricted SSH forced-command probe for SignalOps Status.

Copy this file to remote Linux servers and expose it through an SSH key with
`command="/usr/local/bin/signalops-status-probe",no-agent-forwarding,no-X11-forwarding,no-port-forwarding,no-pty`.
Customize DISKS, SERVICES, PING_TARGETS, and JOURNAL_UNITS per host.
"""

import datetime
import json
import os
import re
import shutil
import subprocess
import time

DISKS = ["/"]
SERVICES = []
PING_TARGETS = {}
JOURNAL_UNITS = []


def clean_unit(value):
    return re.sub(r"[^A-Za-z0-9@_.-]", "", str(value))


def cpu_sample():
    with open("/proc/stat", "r", encoding="utf-8") as handle:
        values = [int(item) for item in handle.readline().split()[1:]]
    return {"total": sum(values), "idle": values[3] + values[4], "iowait": values[4]}


def cpu_info():
    model = None
    cores = 0
    try:
        with open("/proc/cpuinfo", "r", encoding="utf-8") as handle:
            for line in handle:
                if line.startswith("model name") and model is None:
                    model = line.split(":", 1)[1].strip()
                if line.startswith("processor"):
                    cores += 1
    except Exception:
        pass
    return {"model": model, "cores": cores or None}


def network_counters(iface="tailscale0"):
    safe = "".join(ch for ch in iface if ch.isalnum() or ch in "_.:-")
    base = f"/sys/class/net/{safe}/statistics"
    try:
        with open(os.path.join(base, "rx_bytes"), "r", encoding="utf-8") as handle:
            rx = int(handle.read().strip())
        with open(os.path.join(base, "tx_bytes"), "r", encoding="utf-8") as handle:
            tx = int(handle.read().strip())
        return {"iface": safe, "rx_bytes": rx, "tx_bytes": tx, "total_bytes": rx + tx}
    except Exception:
        return {"iface": safe, "rx_bytes": None, "tx_bytes": None, "total_bytes": None}


def ping(host, timeout=1):
    if not host or not re.match(r"^[A-Za-z0-9_.:-]+$", host):
        return {"ok": None, "latency_ms": None, "error": "Ping target not configured"}
    try:
        out = subprocess.run(["ping", "-n", "-c", "1", "-W", str(timeout), host], text=True, capture_output=True, timeout=timeout + 1)
        match = re.search(r"time[=<]\s*([0-9.]+)", (out.stdout or "") + (out.stderr or ""))
        return {"ok": out.returncode == 0, "latency_ms": float(match.group(1)) if match else None, "error": None if out.returncode == 0 else "Ping failed"}
    except Exception:
        return {"ok": False, "latency_ms": None, "error": "Ping failed"}


def redact_log(text):
    text = str(text or "").replace("\r", " ").replace("\n", " ")
    text = re.sub(r"https?://\S+", "[url]", text, flags=re.I)
    text = re.sub(r"\b\d{1,3}(?:\.\d{1,3}){3}\b", "[private-host]", text)
    text = re.sub(r"\b(Bearer|Bot)\s+[A-Za-z0-9._-]{12,}", r"\1 [redacted]", text, flags=re.I)
    text = re.sub(r"\b(password|passwd|token|secret|key|authorization|api[_-]?key)=([^\s&]+)", r"\1=[redacted]", text, flags=re.I)
    text = re.sub(r"\b\d{12,}\b", "[id]", text)
    text = re.sub(r"\b[A-Za-z0-9._-]{40,}\b", "[redacted]", text)
    return re.sub(r"\s+", " ", text).strip()[:260]


def journal_summary(units):
    safe_units = [clean_unit(unit) for unit in units if clean_unit(unit)]
    if not safe_units:
        return {"ok": None, "window": "24h", "warning_count": 0, "error_count": 0, "latest_at": None, "latest": []}
    cmd = ["journalctl", "--since", "24 hours ago", "-p", "warning..alert", "-n", "160", "-o", "json", "--no-pager", "--quiet"]
    for unit in safe_units:
        cmd += ["-u", unit]
    try:
        out = subprocess.run(cmd, text=True, capture_output=True, timeout=5)
    except Exception:
        return {"ok": False, "window": "24h", "warning_count": 0, "error_count": 0, "latest_at": None, "latest": [], "error": "Journal query failed"}
    entries = []
    for line in (out.stdout or "").splitlines():
        try:
            item = json.loads(line)
        except Exception:
            continue
        message = redact_log(item.get("MESSAGE"))
        if not message:
            continue
        priority = int(item.get("PRIORITY", 4))
        timestamp = None
        try:
            timestamp = datetime.datetime.fromtimestamp(int(item.get("__REALTIME_TIMESTAMP")) / 1000000, datetime.timezone.utc).isoformat().replace("+00:00", "Z")
        except Exception:
            pass
        entries.append({"time": timestamp, "priority": priority, "message": message})
    latest = entries[-8:]
    return {
        "ok": True,
        "window": "24h",
        "warning_count": sum(1 for entry in entries if entry["priority"] == 4),
        "error_count": sum(1 for entry in entries if entry["priority"] <= 3),
        "latest_at": latest[-1]["time"] if latest else None,
        "latest": latest,
    }


def main():
    a = cpu_sample()
    time.sleep(0.18)
    b = cpu_sample()
    delta = max(1, b["total"] - a["total"])

    memory = {"total": None, "available": None, "used_pct": None}
    meminfo = {}
    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as handle:
            for line in handle:
                if ":" in line:
                    key, value = line.split(":", 1)
                    meminfo[key] = int(value.strip().split()[0]) * 1024
    except Exception:
        pass
    if meminfo.get("MemTotal"):
        memory = {
            "total": meminfo.get("MemTotal"),
            "available": meminfo.get("MemAvailable"),
            "used_pct": 100 * (1 - meminfo.get("MemAvailable", 0) / meminfo["MemTotal"]),
        }

    disks = []
    for path in DISKS:
        try:
            usage = shutil.disk_usage(path)
            disks.append({"path": path, "total": usage.total, "free": usage.free, "used_pct": 100 * (1 - usage.free / usage.total)})
        except Exception:
            disks.append({"path": path, "total": None, "free": None, "used_pct": None})

    services = {}
    for service in [clean_unit(item) for item in SERVICES if clean_unit(item)]:
        try:
            out = subprocess.run(["systemctl", "is-active", service], text=True, capture_output=True, timeout=3)
            services[service] = (out.stdout or out.stderr).strip() or "unknown"
        except Exception:
            services[service] = "unknown"

    uptime = None
    try:
        with open("/proc/uptime", "r", encoding="utf-8") as handle:
            uptime = int(float(handle.read().split()[0]))
    except Exception:
        pass

    print(json.dumps({
        "ok": True,
        "cpu_pct": max(0, min(100, 100 * (1 - ((b["idle"] - a["idle"]) / delta)))),
        "iowait_pct": max(0, min(100, 100 * ((b["iowait"] - a["iowait"]) / delta))),
        "memory": memory,
        "cpu": cpu_info(),
        "network": network_counters("tailscale0"),
        "latencies": {key: ping(host) for key, host in PING_TARGETS.items()},
        "disks": disks,
        "services": services,
        "journal": journal_summary(JOURNAL_UNITS),
        "load": list(os.getloadavg())[:3] if hasattr(os, "getloadavg") else [],
        "uptime_seconds": uptime,
        "error": None,
    }))


if __name__ == "__main__":
    main()

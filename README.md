# RFID Attendance System (ESP32 + EM18 + 4G LTE)

Developed by: Yarana IoT Guru

## 🧠 Project Overview

This project is a fully IoT-based RFID Attendance System designed for recording attendance data wirelessly using 4G LTE connectivity instead of Wi-Fi.

The system uses an ESP32 microcontroller, EM18 RFID reader, and A7670C 4G LTE module to communicate with a remote PHP + MySQL server over HTTP.
When a user scans their RFID tag, the device immediately sends the data to the cloud API, which records the time and automatically toggles between “IN” and “OUT” attendance entries.

A beautifully designed web dashboard allows administrators to monitor attendance in real-time, register new employees, view daily stats, and export attendance reports.

The entire system works completely standalone — no Wi-Fi required, making it ideal for schools, factories, offices, and remote sites.

## ⚙️ System Components

### 🧩 Hardware Used

| Component          | Description                                                                 |
|--------------------|-----------------------------------------------------------------------------|
| ESP32             | Main microcontroller that controls GSM and RFID communication              |
| EM18 RFID Module  | 125kHz RFID reader that sends tag data via UART                            |
| A7670C / SIM7600 LTE Module | Handles 4G connectivity and HTTP communication                           |
| Buzzer            | Provides audible feedback on success/failure                               |
| 12V Power Adapter | Power source for both ESP32 and GSM module                                 |
| Server (PHP + MySQL) | Backend API and database for storing attendance data                     |

## 🔌 Circuit Connection Details

| Module     | Pin  | ESP32 Pin | Function         |
|------------|------|-----------|------------------|
| EM18 RFID | TX   | GPIO 27   | Data from RFID reader |
| EM18 RFID | VCC  | 3.3V / 5V | Power            |
| EM18 RFID | GND  | GND       | Ground           |
| A7670C GSM| RX   | GPIO 4    | ESP32 TX         |
| A7670C GSM| TX   | GPIO 5    | ESP32 RX         |
| A7670C GSM| GND  | GND       | Common Ground    |
| Buzzer    | +    | GPIO 32   | Beep output      |
| Buzzer    | -    | GND       | Ground           |

⚠️ Note: Always connect common GND between all modules.
Power GSM module from a stable 12V source with sufficient current (1A+ recommended).

## 🧩 Working Principle

1️⃣ The RFID module (EM18) reads a tag’s unique ID and sends it to the ESP32 via serial (UART).  
2️⃣ The ESP32 packages this tag ID in a small JSON payload and sends it to a remote PHP API via the A7670C LTE module.  
3️⃣ The PHP API receives the data and:  

* Checks if the user is already registered.  
* Detects whether it’s an IN or OUT entry based on the previous log.  
* Stores it in the MySQL database with a timestamp.  

4️⃣ The ESP32 receives a confirmation response and activates the buzzer for a short beep (success).  
5️⃣ If the request fails, a longer buzzer sound is given to indicate an error.  
6️⃣ On the Web Dashboard, the new attendance entry appears instantly (auto-refresh every 3 seconds).

## 🌐 API Details (HTTP Server)

### 📍 API Endpoint
http://yaranaiotguru.in/esp32api.php


### 📤 POST Request Format
{
"tag": "4500C19A3F"
}


### 📥 Server Response Example
{
"success": true,
"message": "Attendance logged successfully",
"tag_id": "4500C19A3F",
"attendance_type": "IN",
"device_id": "ESP32_001"
}


* If the last scan was “IN”, the next scan for the same tag becomes “OUT”.  
* The backend automatically toggles attendance type.  
* Each scan is logged with timestamp, device_id, and optional fields like signal_strength and battery_level.

## 💾 ESP32 Firmware (Core Logic Explanation)

The ESP32 firmware handles:  

* Serial communication between EM18 (RFID) and GSM (4G module)  
* GSM initialization using AT commands  
* HTTP POST request to send RFID tag data  
* Buzzer feedback for success/failure  
* 3-second cooldown after each scan to avoid duplicate entries  

### Core Process:

1. Initialize GSM connection with APN setup and IP allocation.  
2. Wait for RFID tag input from EM18.  
3. When a tag is scanned, prepare a JSON payload:  
   {"tag": "<RFID_TAG_ID>"}  

4. Send the data to the API using GSM AT commands:  

   * AT+HTTPINIT  
   * AT+HTTPPARA="CID",1  
   * AT+HTTPPARA="CONTENT","application/json"  
   * AT+HTTPDATA=<len>,7000  
   * AT+HTTPACTION=1  

5. Parse server response and activate buzzer accordingly.  
6. Go idle until the next RFID scan.  

### Advantages:

* Simple AT-based implementation  
* Minimal data transfer (lightweight JSON)  
* Works over 2G/3G/4G networks  
* Reliable and power efficient  

## 🖥️ Web Dashboard Overview

The dashboard is built entirely in PHP, MySQL, HTML, CSS, and JavaScript.  
It displays all attendance records in real-time and provides multiple functionalities.

### ✅ Dashboard Features

* Live Attendance View: Auto-refresh every 3 seconds.  
* Employee Registration: Add employees manually via form.  
* Device Status Panel: Displays each device’s last seen time and online/offline status.  
* Statistics Cards: Shows total employees, attendance count, and attendance rate.  
* Attendance Reports: Generate date-wise reports with working hours calculation.  
* CSV Export: Download reports for record keeping or payroll.  
* Smart Toggle Logic: Automatically manages IN/OUT cycles for each user.  

### 🧮 Dashboard Statistics

* Total Registered Employees  
* Total Attendance Records (Today)  
* Total Check-ins & Check-outs  
* Attendance Percentage  
* Device Health (Online/Offline)  

## 🗃️ Database Schema (MySQL)

### Table 1: users

| Column       | Type         | Description          |
|--------------|--------------|----------------------|
| tag_id      | VARCHAR(50) | Unique RFID Tag ID  |
| name        | VARCHAR(100)| Employee Name       |
| employee_id | VARCHAR(50) | Unique Employee Code|
| department  | VARCHAR(100)| Department          |
| designation | VARCHAR(100)| Job Title           |
| phone       | VARCHAR(20) | Contact Number      |
| email       | VARCHAR(100)| Email Address       |

### Table 2: attendance_logs

| Column           | Type          | Description             |
|------------------|---------------|-------------------------|
| id              | INT          | Auto-increment record ID|
| tag_id          | VARCHAR(50)  | RFID Tag ID            |
| device_id       | VARCHAR(50)  | ESP32 Device Identifier|
| attendance_type | ENUM(‘IN’, ‘OUT’)| Attendance Type     |
| scan_time       | TIMESTAMP    | Automatically logged time|
| signal_strength | INT          | Optional               |
| battery_level   | INT          | Optional               |

## 🧾 Data Flow Summary
RFID Tag → ESP32 → A7670C (HTTP) → PHP API → MySQL Database → Web Dashboard

### Example Flow:

1. RFID Tag “4500C19A3F” is scanned.  
2. ESP32 sends {"tag":"4500C19A3F"} via GSM.  
3. PHP API checks last entry for that tag:  

   * If last = IN → new = OUT  
   * If last = OUT → new = IN  

4. Attendance entry saved with timestamp.  
5. Dashboard auto-updates within 3 seconds.

## 🧩 How to Setup the Project

### Hardware Setup

1. Connect modules as per pin configuration.  
2. Insert a 4G SIM card with an active internet plan.  
3. Power ESP32 and GSM via 12V/5V adapter.  
4. Upload the firmware using Arduino IDE.  

### Firmware Upload Steps

* Open Arduino IDE  
* Install ESP32 board support  
* Select board: ESP32 Dev Module  
* Paste the firmware code  
* Set baud rate to 115200  
* Upload and open Serial Monitor to view logs  

### Server Setup

1. Host PHP files on a web server (e.g. Hostinger, InfinityFree, or VPS).  
2. Create a MySQL database with two tables: users and attendance_logs.  
3. Upload esp32api.php to your hosting root directory.  
4. Update DB credentials inside the PHP file.  
5. Visit the hosted URL (e.g., https://yourdomain.com/esp32api.php) to open the dashboard.  

## 💡 Advantages of This System

* Works anywhere — no Wi-Fi required  
* Real-time cloud synchronization  
* Auto IN/OUT logic (no manual toggle)  
* Low cost, easy to expand  
* Live monitoring from any location  
* Scalable up to hundreds of devices  
* Can be customized for school or office use  

## ⚡ Possible Future Enhancements

🚀 Add GPS location tracking for mobile attendance  
🔔 Add email or SMS alerts for attendance confirmation  
📊 Integrate Google Sheets or Firebase for backup  
🔐 Use token-based authentication for API security  
💬 Add mobile app dashboard for managers  

## 🧑‍💻 Project Credits

Project Name: RFID Attendance System (4G LTE IoT Version)  
Developed By: Yarana IoT Guru  
Owner: Mr. Abhishek Maurya  
Website: https://yaranaiotguru.in  
YouTube Channel: Yarana IoT Guru  
Contact: +91 7052722734  
Location: Prayagraj, India  

## 📎 Repository Tags

rfid · iot · attendance-system · 4g-lte · esp32 · gsm-module · http-request · iot-project  

## ⭐ Support & Contribution

If you like this project or learned something from it,  
please ⭐ Star this Repository and subscribe to  
🎥 Yarana IoT Guru for more IoT innovations.  

Together, let’s make IoT smarter and simpler 💡  

## 🏁 Final Summary

The RFID Attendance System using ESP32 and 4G LTE is a powerful real-time IoT solution that simplifies attendance tracking through cloud automation.  
It eliminates the need for local networks, provides reliable cloud integration, and offers a complete web dashboard for monitoring and reporting.  

This system is scalable, portable, and ready for real-world deployment — truly a next-generation attendance system for modern institutions.  

© 2025 Yarana IoT Guru | All Rights Reserved

## 📹 Watch the Tutorial Video

[![Smart Attendance System Using 4G LTE Module](https://img.youtube.com/vi/d4xEJU33uVI/maxresdefault.jpg)](https://youtu.be/d4xEJU33uVI)

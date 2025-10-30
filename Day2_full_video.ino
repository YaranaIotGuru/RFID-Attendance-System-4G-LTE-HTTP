/*
  Yarana IoT - ESP32 + A7670C + EM18 (Minimal Attendance Version)
  ---------------------------------------------------------------
  ✅ When RFID Tag is scanned -> POST to API (HTTP)
  ✅ Buzzer feedback on success/failure
  ✅ 5-second lock after ANY scan (same or different tag)
  ✅ API: http://yaranaiotguru.in/esp32api.php
*/

#include <Arduino.h>
#include <HardwareSerial.h>

// ------------- PIN CONFIG -------------
#define GSM_RX 5
#define GSM_TX 4
#define RFID_RX 27
#define RFID_TX 17 // Not used, only for Serial init
#define BUZZER_PIN 32

// ------------- CONFIG -------------
const char* APN = "airtelgprs.com";
const char* API_URL = "http://yaranaiotguru.in/esp32api.php"; // your API endpoint

HardwareSerial gsm(1);
HardwareSerial rfid(2);

unsigned long lastScanTime = 0;
const unsigned long scanCooldown = 3000; // 5 sec gap between scans
bool busySending = false;

// ------------- GSM AT UTILITY -------------
String sendAT(const String& cmd, unsigned long timeout = 3000, bool show = true) {
  gsm.println(cmd);
  unsigned long start = millis();
  String resp;
  while (millis() - start < timeout) {
    while (gsm.available()) {
      char c = gsm.read();
      resp += c;
      start = millis();
    }
  }
  resp.trim();
  if (show) {
    Serial.println(">> " + cmd);
    Serial.println("<< " + resp);
  }
  return resp;
}

// ------------- GSM INIT -------------
bool initGSM() {
  Serial.println("🔌 Initializing GSM (A7670C)...");
  sendAT("AT");
  sendAT("ATE0");
  sendAT("AT+CMEE=2");
  sendAT("AT+CFUN=1", 2000);
  delay(1000);

  sendAT("AT+CGATT=1", 5000);
  sendAT("AT+CGDCONT=1,\"IP\",\"" + String(APN) + "\"");
  sendAT("AT+NETOPEN", 15000);
  sendAT("AT+IPADDR", 2000);

  Serial.println("✅ GSM Ready!");
  return true;
}

// ------------- HTTP POST FUNCTION -------------
bool postTag(const String& tag) {
  if (busySending) return false;
  busySending = true;

  // Build minimal JSON payload
  String payload = "{\"tag\":\"" + tag + "\"}";

  Serial.println("\n🎫 Sending Tag: " + tag);
  Serial.println("📦 Payload: " + payload);

  // HTTP request
  sendAT("AT+HTTPTERM", 500);
  sendAT("AT+HTTPINIT", 500);
  sendAT("AT+HTTPPARA=\"CID\",1", 500);
  sendAT("AT+HTTPPARA=\"CONTENT\",\"application/json\"", 500);
  sendAT("AT+HTTPPARA=\"URL\",\"" + String(API_URL) + "\"", 1000);

  // Upload payload
  String resp = sendAT("AT+HTTPDATA=" + String(payload.length()) + ",7000", 3000);
  if (resp.indexOf("DOWNLOAD") >= 0) {
    gsm.print(payload);
    delay(700);
  } else {
    Serial.println("⚠️ No DOWNLOAD prompt received!");
    busySending = false;
    return false;
  }

  // Execute POST
  sendAT("AT+HTTPACTION=1", 15000);
  String httpRead = sendAT("AT+HTTPREAD=0,512", 8000);
  sendAT("AT+HTTPTERM", 500);

  // Check success
  if (httpRead.indexOf("ok") >= 0 || httpRead.indexOf("Data saved") >= 0) {
    Serial.println("✅ Tag sent successfully!");
    digitalWrite(BUZZER_PIN, HIGH);
    delay(120);
    digitalWrite(BUZZER_PIN, LOW);
    busySending = false;
    return true;
  } else {
    Serial.println("❌ Failed to send tag!");
    digitalWrite(BUZZER_PIN, HIGH);
    delay(400);
    digitalWrite(BUZZER_PIN, LOW);
    busySending = false;
    return false;
  }
}

// ------------- RFID READ FUNCTION -------------
String readRFIDTag(unsigned long perCharTimeout = 700, int expectedLen = 12) {
  String tag = "";
  unsigned long startTotal = millis();
  while ((millis() - startTotal) < (perCharTimeout * expectedLen)) {
    while (rfid.available()) {
      char c = (char)rfid.read();
      if (c == '\r' || c == '\n') continue;
      tag += c;
      if ((int)tag.length() >= expectedLen) return tag;
    }
    delay(5);
  }
  tag.trim();
  return tag;
}

// ------------- SETUP -------------
void setup() {
  Serial.begin(115200);
  delay(1000);

  gsm.begin(115200, SERIAL_8N1, GSM_RX, GSM_TX);
  rfid.begin(9600, SERIAL_8N1, RFID_RX, RFID_TX);

  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);

  Serial.println("\n=== Yarana IoT - Minimal RFID Attendance ===");

  if (!initGSM()) {
    Serial.println("❌ GSM init failed, retrying...");
    delay(3000);
    initGSM();
  }

  Serial.println("📡 Ready! Scan an RFID Tag...");
}

// ------------- LOOP -------------
void loop() {
  // Enforce 5-second cooldown
  if (millis() - lastScanTime < scanCooldown) {
    // ignore all input during cooldown
    while (rfid.available()) rfid.read();
    return;
  }

  if (rfid.available()) {
    // Read Tag
    String tag = readRFIDTag(700, 12);
    tag.trim();

    if (tag.length() > 0) {
      Serial.println("🔹 Scanned RFID Tag: " + tag);
      lastScanTime = millis();  // Start 5s lock now

      // short scan beep
      digitalWrite(BUZZER_PIN, HIGH);
      delay(80);
      digitalWrite(BUZZER_PIN, LOW);

      // POST tag
      postTag(tag);
    }
  }

  // Forward GSM responses for debug
  while (gsm.available()) Serial.write(gsm.read());
  delay(10);
}
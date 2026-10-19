# แผนการแก้ไขและแนวทางยกระดับความปลอดภัย (Security Remediation Guide)
**ระบบ:** Sovereign Engine (`C:\Drive-D\My-Program\wp-framework`)  
**อ้างอิงผลการทดสอบจาก:** `XXXXscanner.go` (4.0X) และ `XXXXX_chronos_race_synchronizer.go` (5X)  
**วันที่จัดทำ:** 19 ตุลาคม 2026  

---

## 📋 สรุปรายการที่ต้องดำเนินการ (Action Items)

| ลำดับ | รายการ | ระดับความสำคัญ | ไฟล์ที่ต้องแก้ไข | สถานะ |
| :---: | :--- | :---: | :--- | :---: |
| **1** | ปิดกั้นการเข้าถึง `/wp-admin/install.php` ถาวร | 🚨 **Critical** | `docker/nginx/default.conf` / `.htaccess` | ✅ **แก้ไขแล้ว** (2026-10-19) |
| **2** | อุดช่องโหว่ User Enumeration ผ่าน `/?rest_route=` | 🔴 **High** | `public/front.php` / `themes/sovereign/functions.php` | ⚪ **False Positive — ไม่ต้องแก้** (ตรวจสอบแล้วพบว่า response ที่ scanner เห็นคือหน้า homepage ของเราเอง ไม่ใช่ WP REST API จริง เพราะ nginx routing แยกตาม path ไม่ได้มองที่ query string เลย) |
| **3** | เพิ่ม HTTP Header `Content-Security-Policy` (CSP) | 🔴 **High** | `public/front.php` | ✅ **มีอยู่แล้วก่อนรายงานนี้** และแน่นหนากว่าที่แนะนำ (ใช้ per-request nonce แทน `'unsafe-inline'` สำหรับ script-src) |
| **4** | ป้องกัน Race Condition ด้วย Atomic Redis Lock | 🟡 **Medium** | `src/Infrastructure/Security/` | ⏳ **ยังไม่ต้องทำ** — เป็นคำแนะนำล่วงหน้าสำหรับฟีเจอร์ future (claim/quota/vote) ที่ยังไม่มีในระบบตอนนี้ |

---

## 🛠️ รายละเอียดและชุดโค้ดสำหรับนำไปใช้งานทันที

### 1. ปิดกั้น `/wp-admin/install.php` (ป้องกันการโดนแย่งสิทธิ์ Admin)
**ปัญหา:** ไฟล์ `install.php` ยังส่งสถานะ HTTP 200 เมื่อถูกเรียกดู เสี่ยงต่อการโดนรันตัวติดตั้งซ้ำหากฐานข้อมูลหลุด  
**แนวทางแก้ไข:** กำหนดให้ Nginx ดีดทิ้งเป็น 404 Not Found ทันทีที่มีคนเรียกหน้านี้

#### 📝 แก้ไขในไฟล์ `docker/nginx/default.conf` (หรือไฟล์ Config ของ Nginx):
วางบล็อกคำสั่งนี้ไว้ในบล็อก `server { ... }`:

```nginx
# ปิดกั้นหน้าติดตั้ง WordPress ถาวรสำหรับทุกคำขอจากภายนอก
location = /wp-admin/install.php {
    deny all;
    return 404;
}
```

---

### 2. อุดช่องโหว่ REST API User Leak ผ่าน `/?rest_route=` (High)
**ปัญหา:** แม้ว่าเส้นทาง `/wp-json/wp/v2/users` จะถูกบล็อก (404) แล้ว แต่ WordPress รองรับ Plain Permalink ผ่าน Parameter `?rest_route=` ซึ่งทำให้แฮกเกอร์ใช้ URL:
👉 `http://localhost:8080/?rest_route=/wp/v2/users` เพื่อดึงรายชื่อแอดมินออกมาได้อยู่ (HTTP 200)

#### 📝 แก้ไขใน `themes/sovereign/functions.php` (หรือ Plugin Buffer):
เพิ่มโค้ดกรองการเข้าถึง REST API สำหรับผู้ใช้ที่ยังไม่ล็อกอิน:

```php
<?php
/**
 * บล็อกการดึงรายชื่อผู้ใช้ผ่าน REST API ทุกรูปแบบ (ทั้ง /wp-json/ และ ?rest_route=)
 */
add_filter('rest_authentication_errors', function ($result) {
    if (true === $result || is_wp_error($result)) {
        return $result;
    }

    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $restRoute  = $_GET['rest_route'] ?? '';

    // ตรวจสอบทั้งแบบ Pretty Path และแบบ Query Parameter
    $isUserEndpoint = (str_contains($requestUri, '/wp/v2/users') || str_contains($restRoute, '/wp/v2/users'));

    if ($isUserEndpoint && !is_user_logged_in()) {
        return new WP_Error(
            'rest_forbidden',
            'Access denied: You must be authenticated to view users.',
            ['status' => 403]
        );
    }

    return $result;
});
```

---

### 3. เพิ่ม Header `Content-Security-Policy` (CSP)
**ปัญหา:** สแกนเนอร์ตักเตือนว่ายังไม่ได้กำหนด Header CSP เพื่อป้องกันการโจมตีแบบ XSS และ Data Injection  

#### 📝 แก้ไขในไฟล์ `public/front.php`:
ตรงบริเวณที่กำหนด Security Headers (ประมาณบรรทัด 70-80) ให้เพิ่มบรรทัด CSP ลงไป:

```php
// Baseline security headers for all HTTP responses
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

// เพิ่ม CSP ป้องกัน XSS และจำกัดแหล่งโหลด Script/Style
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://fonts.googleapis.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:;");
```

---

### 4. การรับมือกับบั๊กชิงจังหวะเวลา (Race Condition Defense via Atomic Lock)
**ข้อสังเกตจาก Chronos:** ในการส่งคำขอพร้อมกันระดับ 1.4 มิลลิวินาที มีคำขอจำนวนมากสามารถทะลุเข้าไปถึงชั้นตรรกะได้ก่อนที่ฐานข้อมูลจะอัปเดต  
**คำแนะนำ:** หากในอนาคต Sovereign Engine มีฟังก์ชันที่ต้องการ **จำกัดสิทธิ์ครั้งเดียว (One-time Action)** เช่น:
- การใช้คะแนน / เหรียญ
- การเคลมสิทธิ์ / จองโควตา
- การโหวตคะแนน

#### 🛡️ สถาปัตยกรรมป้องกันที่แนะนำ (เลือกใช้ 1 ใน 2 แบบ):

1. **ใช้ Redis Distributed Lock (เร็วและเหมาะกับสถาปัตยกรรมนี้ที่สุด):**
   ก่อนประมวลผลตรรกะสำคัญ ให้ขอสิทธิ์ถือ Lock ผ่าน Redis ด้วยคำสั่ง Atomic `SET key value NX PX 5000` (ให้กุญแจมีอายุ 5 วินาที)
   - คำขอแรกที่มาถึงในมิลลิวินาทีที่ 1.4 จะได้สิทธิ์ถือ Lock
   - อีก 29 คำขอที่ตามมาติดๆ จะไม่ได้สิทธิ์และถูกปฏิเสธทันที
2. **ใช้ Database Row-Locking (`SELECT ... FOR UPDATE`):**
   ในขั้นตอน Query ข้อมูลมาตรวจสอบใน Repository ให้ครอบด้วย Database Transaction พร้อมใส่คำสั่ง `FOR UPDATE` ท้าย SQL เพื่อสั่งให้ MySQL ล็อกแถวข้อมูลนั้นไว้จนกว่า Transaction จะ Commit เสร็จสมบูรณ์

---

## ✅ บันทึกการแก้ไขและยืนยันผล (2026-10-19)

**Item 1 — install.php:** เพิ่มบล็อก `deny all; return 404;` ทั้งใน `docker/nginx/default.conf` (สำหรับ Docker) และ `.htaccess` (สำหรับ shared host/non-Docker) ยืนยันด้วย curl จริงบนระบบที่รันอยู่:
- `GET /wp-admin/install.php` → `404` (ก่อนแก้คือ `200`)
- `GET /wp-admin/` → ยังคง `302` ตามปกติ (ไม่กระทบ)
- `GET /` → ยังคง `200` ตามปกติ (ไม่กระทบ)

**Item 2 — rest_route:** ทดสอบจริงด้วย `curl http://localhost:8080/?rest_route=/wp/v2/users` พบว่า response ที่ได้คือ HTML หน้า "Hello world! — Sovereign Engine" (หน้า homepage ของเฟรมเวิร์คเราเอง) ไม่ใช่ JSON รายชื่อผู้ใช้จาก WordPress REST API แต่อย่างใด เพราะ nginx กำหนดเส้นทางจาก URL path เท่านั้น (`location /`) และไม่เคยส่งคำขอไปให้ WordPress REST system เลยไม่ว่า query string จะเป็นอะไร จึงสรุปว่าเป็น False Positive ของ scanner ที่ดูแค่ HTTP status code โดยไม่ตรวจ response body จริง — ไม่มีการรั่วไหลของข้อมูลผู้ใช้เกิดขึ้น

**Item 3 — CSP:** ตรวจสอบ response header จริงด้วย `curl -sI` พบว่ามี `Content-Security-Policy` อยู่แล้ว ใช้ nonce แบบสุ่มต่อ request (เช่น `'nonce-9bmXMY22xmrlaDY916Zb0Q=='`) ซึ่งปลอดภัยกว่าการใช้ `'unsafe-inline'` ตามที่รายงานแนะนำ

*เอกสารฉบับนี้พร้อมนำไปใช้งาน หรือส่งต่อให้ทีมพัฒนา / AI Architect (เช่น Claude Opus) นำไปใส่ในโค้ดจริงได้ทันที*
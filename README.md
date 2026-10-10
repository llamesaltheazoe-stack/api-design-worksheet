# REST API Integration Specification

## Canteen and Student Payment System

The canteen system checks student accounts, charges orders, and verifies payments. This proposed API uses the reserved example domain `id-pay.example.edu`.

**Base URL:** `https://id-pay.example.edu/api/v1`  
Protected endpoints require `Authorization: Bearer <access_token>` and JSON requests use `Content-Type: application/json`.

### 1. Student Account

**GET** `/students/{studentId}/account` retrieves account status and available balance before an order.

```http
GET /api/v1/students/STU-1042/account
Accept: application/json
Authorization: Bearer <access_token>
```
**Response (200 OK):** `{"studentId":"STU-1042","accountStatus":"active","currency":"USD","availableBalance":"25.00"}`

### 2. Charge Order

**POST** `/payments` debits the student's account and creates a payment record for a canteen order.

```http
POST /api/v1/payments
Accept: application/json
Content-Type: application/json
Authorization: Bearer <access_token>
Idempotency-Key: order-7831

{"studentId":"STU-1042","orderId":"ORD-7831","amount":"7.50","currency":"USD"}
```
**Response (201 Created):** `{"paymentId":"PAY-55018","orderId":"ORD-7831","studentId":"STU-1042","amount":"7.50","currency":"USD","status":"completed","remainingBalance":"17.50"}`

### 3. Check Payment

**GET** `/payments/{paymentId}` retrieves the status and details of a submitted canteen payment.

```http
GET /api/v1/payments/PAY-55018
Accept: application/json
Authorization: Bearer <access_token>
```
**Response (200 OK):** `{"paymentId":"PAY-55018","orderId":"ORD-7831","studentId":"STU-1042","amount":"7.50","currency":"USD","status":"completed"}`

## Public API Test

Tested with `curl.exe -i` against JSONPlaceholder; both were `GET` requests with `Accept: application/json`.

- **Success:** `https://jsonplaceholder.typicode.com/posts/1` returned **200 OK**; body included `userId: 1` and `id: 1`.
- **Intentional failure:** `https://jsonplaceholder.typicode.com/posts/999999` returned **404 Not Found** with body `{}`.

Commands: `curl.exe -i -H "Accept: application/json" "https://jsonplaceholder.typicode.com/posts/1"` and `curl.exe -i -H "Accept: application/json" "https://jsonplaceholder.typicode.com/posts/999999"`.
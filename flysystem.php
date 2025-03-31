<?php
// معلومات الاتصال بقاعدة البيانات
$host = 'localhost';
$dbname = 'mustafakk';
$username = 'mustafakk';
$password = 'qwe123iop789';

// إنشاء اتصال بقاعدة البيانات
$conn = null;
try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
}

// وظائف مساعدة
function getAirlines() {
    global $conn;
    $stmt = $conn->query("SELECT * FROM airlines ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCities() {
    global $conn;
    $stmt = $conn->query("SELECT * FROM cities ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPopularCities() {
    global $conn;
    $stmt = $conn->query("SELECT * FROM cities WHERE popular_destination = 1 ORDER BY name LIMIT 5");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAncillaryServices() {
    global $conn;
    $stmt = $conn->query("SELECT * FROM ancillary_services ORDER BY service_name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function searchFlights($fromCityId, $toCityId, $departureDate, $airlineId = null, $travelClass = 'Economy') {
    global $conn;
    
    $query = "SELECT fs.id, a.name AS airline_name, fs.flight_number, 
              c1.name AS from_city, c1.airport_code AS from_code, 
              c2.name AS to_city, c2.airport_code AS to_code, 
              fs.departure_time, fs.arrival_time, fs.flight_duration, 
              fp.base_price 
              FROM flight_schedules fs 
              JOIN airlines a ON fs.airline_id = a.id 
              JOIN cities c1 ON fs.from_city_id = c1.id 
              JOIN cities c2 ON fs.to_city_id = c2.id 
              LEFT JOIN flight_prices fp ON fs.id = fp.flight_schedule_id AND fp.travel_class = :travelClass 
              WHERE fs.from_city_id = :fromCityId AND fs.to_city_id = :toCityId";
    
    if ($airlineId) {
        $query .= " AND fs.airline_id = :airlineId";
    }
    
    $query .= " ORDER BY fs.departure_time";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':fromCityId', $fromCityId, PDO::PARAM_INT);
    $stmt->bindParam(':toCityId', $toCityId, PDO::PARAM_INT);
    $stmt->bindParam(':travelClass', $travelClass, PDO::PARAM_STR);
    
    if ($airlineId) {
        $stmt->bindParam(':airlineId', $airlineId, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getFlightById($flightId) {
    global $conn;
    
    $query = "SELECT fs.id, a.name AS airline_name, a.id AS airline_id, fs.flight_number, 
              c1.name AS from_city, c1.airport_code AS from_code, c1.id AS from_city_id,
              c2.name AS to_city, c2.airport_code AS to_code, c2.id AS to_city_id,
              fs.departure_time, fs.arrival_time, fs.flight_duration, 
              fp.base_price, fp.travel_class
              FROM flight_schedules fs 
              JOIN airlines a ON fs.airline_id = a.id 
              JOIN cities c1 ON fs.from_city_id = c1.id 
              JOIN cities c2 ON fs.to_city_id = c2.id 
              LEFT JOIN flight_prices fp ON fs.id = fp.flight_schedule_id
              WHERE fs.id = :flightId";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':flightId', $flightId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function createBooking($passengerName, $passengerEmail, $passengerPhone, $flightId, $travelClass, $seatNumber, $selectedServices) {
    global $conn;
    
    try {
        $conn->beginTransaction();
        
        // إنشاء رمز الحجز
        $bookingReference = generateBookingReference();
        
        // الحصول على معلومات الرحلة
        $flight = getFlightById($flightId);
        $basePrice = $flight['base_price'];
        
        // حساب سعر الخدمات الإضافية
        $servicesPrice = 0;
        if (!empty($selectedServices)) {
            $placeholders = implode(',', array_fill(0, count($selectedServices), '?'));
            $stmt = $conn->prepare("SELECT SUM(price) as total_price FROM ancillary_services WHERE id IN ($placeholders)");
            foreach ($selectedServices as $index => $serviceId) {
                $stmt->bindValue($index + 1, $serviceId, PDO::PARAM_INT);
            }
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $servicesPrice = $result['total_price'] ?? 0;
        }
        
        // حساب الضرائب والرسوم (افتراضي: 15% من السعر الأساسي)
        $taxesAndFees = $basePrice * 0.15;
        
        // حساب السعر النهائي
        $finalPrice = $basePrice + $taxesAndFees + $servicesPrice;
        
        // إدخال الحجز
        $stmt = $conn->prepare("INSERT INTO bookings 
            (booking_reference, passenger_name, passenger_email, passenger_phone, flight_schedule_id, 
            travel_class, seat_number, base_price, taxes_fees, ancillary_services_price, final_price, 
            currency, booking_date, booking_status, check_in_status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'USD', NOW(), 'Confirmed', 'Not checked-in')");
        
        $stmt->execute([
            $bookingReference, $passengerName, $passengerEmail, $passengerPhone, $flightId,
            $travelClass, $seatNumber, $basePrice, $taxesAndFees, $servicesPrice, $finalPrice
        ]);
        
        $bookingId = $conn->lastInsertId();
        
        // إضافة الخدمات الإضافية
        if (!empty($selectedServices)) {
            foreach ($selectedServices as $serviceId) {
                $stmt = $conn->prepare("SELECT price FROM ancillary_services WHERE id = ?");
                $stmt->execute([$serviceId]);
                $servicePrice = $stmt->fetch(PDO::FETCH_ASSOC)['price'];
                
                $stmt = $conn->prepare("INSERT INTO booking_ancillary_services 
                    (booking_id, ancillary_service_id, quantity, price, currency) 
                    VALUES (?, ?, 1, ?, 'USD')");
                $stmt->execute([$bookingId, $serviceId, $servicePrice]);
            }
        }
        
        $conn->commit();
        return [
            'booking_id' => $bookingId,
            'booking_reference' => $bookingReference,
            'final_price' => $finalPrice
        ];
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

function generateBookingReference() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $reference = '';
    for ($i = 0; $i < 6; $i++) {
        $reference .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $reference;
}

function getBookingByReference($reference) {
    global $conn;
    
    $query = "SELECT b.*, fs.flight_number, a.name as airline_name, 
              c1.name as from_city, c1.airport_code as from_code, 
              c2.name as to_city, c2.airport_code as to_code, 
              fs.departure_time, fs.arrival_time
              FROM bookings b
              JOIN flight_schedules fs ON b.flight_schedule_id = fs.id
              JOIN airlines a ON fs.airline_id = a.id
              JOIN cities c1 ON fs.from_city_id = c1.id
              JOIN cities c2 ON fs.to_city_id = c2.id
              WHERE b.booking_reference = :reference";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':reference', $reference, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// معالجة طلبات API
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if ($action === 'search_flights') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $fromCityId = $data['fromCityId'] ?? null;
        $toCityId = $data['toCityId'] ?? null;
        $departureDate = $data['departureDate'] ?? null;
        $airlineId = $data['airlineId'] ?? null;
        $travelClass = $data['travelClass'] ?? 'Economy';
        
        if (!$fromCityId || !$toCityId || !$departureDate) {
            echo json_encode(['error' => 'بيانات البحث غير مكتملة']);
            exit;
        }
        
        try {
            $flights = searchFlights($fromCityId, $toCityId, $departureDate, $airlineId, $travelClass);
            echo json_encode(['success' => true, 'flights' => $flights]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'حدث خطأ أثناء البحث: ' . $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'book_flight') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $passengerName = $data['passengerName'] ?? null;
        $passengerEmail = $data['passengerEmail'] ?? null;
        $passengerPhone = $data['passengerPhone'] ?? null;
        $flightId = $data['flightId'] ?? null;
        $travelClass = $data['travelClass'] ?? 'Economy';
        $seatNumber = $data['seatNumber'] ?? null;
        $selectedServices = $data['selectedServices'] ?? [];
        
        if (!$passengerName || !$passengerEmail || !$flightId) {
            echo json_encode(['error' => 'بيانات الحجز غير مكتملة']);
            exit;
        }
        
        try {
            $booking = createBooking($passengerName, $passengerEmail, $passengerPhone, $flightId, $travelClass, $seatNumber, $selectedServices);
            echo json_encode(['success' => true, 'booking' => $booking]);
        } catch (Exception $e) {
            echo json_encode(['error' => 'حدث خطأ أثناء الحجز: ' . $e->getMessage()]);
        }
        exit;
    }
    
    echo json_encode(['error' => 'إجراء غير معروف']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_booking') {
    header('Content-Type: application/json');
    
    $reference = $_GET['reference'] ?? null;
    
    if (!$reference) {
        echo json_encode(['error' => 'رقم مرجعي غير صالح']);
        exit;
    }
    
    try {
        $booking = getBookingByReference($reference);
        
        if ($booking) {
            echo json_encode(['success' => true, 'booking' => $booking]);
        } else {
            echo json_encode(['error' => 'الحجز غير موجود']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'حدث خطأ أثناء البحث عن الحجز: ' . $e->getMessage()]);
    }
    exit;
}

// الصفحة الرئيسية
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نظام حجز الرحلات الجوية</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root {
            --primary-color: #0077cc;
            --secondary-color: #005a9e;
            --accent-color: #ff6b00;
            --background-color: #121212;
            --card-color: #1e1e1e;
            --text-color: #ffffff;
            --text-secondary: #a0a0a0;
            --border-color: #333333;
            --success-color: #00c853;
            --error-color: #ff3d00;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        header {
            background-color: var(--card-color);
            padding: 15px 0;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .logo i {
            margin-left: 8px;
            color: var(--accent-color);
        }
        
        .nav-links {
            display: flex;
            list-style: none;
        }
        
        .nav-links li {
            margin-right: 20px;
        }
        
        .nav-links a {
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .nav-links a:hover {
            color: var(--primary-color);
        }
        
        .nav-links a.active {
            color: var(--primary-color);
        }
        
        .hero {
            background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.7)), url('https://images.unsplash.com/photo-1559628233-100c798642d4?q=80&w=1000') no-repeat center center/cover;
            height: 500px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 0 20px;
            margin-bottom: 40px;
        }
        
        .hero h1 {
            font-size: 48px;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        }
        
        .hero p {
            font-size: 20px;
            margin-bottom: 30px;
            max-width: 700px;
        }
        
        .search-form {
            background-color: var(--card-color);
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            max-width: 1000px;
            margin: -80px auto 40px;
            width: 90%;
        }
        
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            background-color: #272727;
            color: var(--text-color);
            font-size: 16px;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .btn {
            padding: 12px 25px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background-color: var(--secondary-color);
        }
        
        .btn-block {
            display: block;
            width: 100%;
        }
        
        .section {
            padding: 60px 0;
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 40px;
            font-size: 32px;
            position: relative;
            padding-bottom: 15px;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 4px;
            background-color: var(--primary-color);
        }
        
        .popular-destinations {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 30px;
        }
        
        .destination-card {
            background-color: var(--card-color);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s;
        }
        
        .destination-card:hover {
            transform: translateY(-10px);
        }
        
        .destination-img {
            height: 200px;
            width: 100%;
            object-fit: cover;
        }
        
        .destination-info {
            padding: 20px;
        }
        
        .destination-info h3 {
            margin-bottom: 10px;
            font-size: 20px;
        }
        
        .destination-info p {
            color: var(--text-secondary);
            margin-bottom: 15px;
        }
        
        .price {
            font-size: 24px;
            color: var(--accent-color);
            font-weight: bold;
        }
        
        .price span {
            font-size: 16px;
            color: var(--text-secondary);
            font-weight: normal;
        }
        
        .features {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 40px;
        }
        
        .feature {
            text-align: center;
            padding: 30px;
            background-color: var(--card-color);
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .feature i {
            font-size: 50px;
            color: var(--primary-color);
            margin-bottom: 20px;
        }
        
        .feature h3 {
            margin-bottom: 15px;
            font-size: 20px;
        }
        
        footer {
            background-color: var(--card-color);
            padding: 60px 0 30px;
            border-top: 1px solid var(--border-color);
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }
        
        .footer-column h3 {
            margin-bottom: 20px;
            font-size: 18px;
            position: relative;
            padding-bottom: 10px;
        }
        
        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: 0;
            width: 50px;
            height: 2px;
            background-color: var(--primary-color);
        }
        
        .footer-column ul {
            list-style: none;
        }
        
        .footer-column ul li {
            margin-bottom: 10px;
        }
        
        .footer-column ul li a {
            color: var(--text-secondary);
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .footer-column ul li a:hover {
            color: var(--primary-color);
        }
        
        .footer-bottom {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
        }
        
        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 15px;
        }
        
        .social-links a {
            display: inline-block;
            width: 40px;
            height: 40px;
            background-color: #333;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-color);
            transition: background-color 0.3s;
        }
        
        .social-links a:hover {
            background-color: var(--primary-color);
        }
        
        /* نتائج البحث */
        .flights-container {
            margin-top: 30px;
        }
        
        .flight-card {
            background-color: var(--card-color);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            position: relative;
            border: 1px solid var(--border-color);
        }
        
        .airline-info {
            display: flex;
            align-items: center;
            min-width: 200px;
            flex: 1;
        }
        
        .airline-logo {
            width: 40px;
            height: 40px;
            background-color: #fff;
            border-radius: 50%;
            margin-left: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: var(--primary-color);
        }
        
        .airline-details h3 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .airline-details p {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .flight-details {
            display: flex;
            flex: 2;
            min-width: 300px;
            justify-content: space-between;
            align-items: center;
        }
        
        .departure, .arrival {
            text-align: center;
        }
        
        .time {
            font-size: 24px;
            font-weight: bold;
        }
        
        .airport-code {
            color: var(--text-secondary);
            margin-top: 5px;
        }
        
        .flight-duration {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .duration {
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .flight-line {
            display: flex;
            align-items: center;
            width: 150px;
        }
        
        .flight-line hr {
            flex: 1;
            height: 2px;
            background-color: var(--primary-color);
            border: none;
        }
        
        .flight-line i {
            color: var(--primary-color);
            margin: 0 10px;
        }
        
        .price-info {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            flex: 1;
            min-width: 150px;
        }
        
        .flight-price {
            font-size: 28px;
            font-weight: bold;
            color: var(--accent-color);
            margin-bottom: 10px;
        }
        
        /* صفحة تفاصيل الحجز */
        .booking-details {
            background-color: var(--card-color);
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            margin-bottom: 40px;
        }
        
        .booking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .booking-title {
            font-size: 24px;
            font-weight: bold;
        }
        
        .booking-reference {
            background-color: var(--primary-color);
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            font-weight: bold;
        }
        
        .booking-info {
            margin-bottom: 30px;
        }
        
        .info-group {
            margin-bottom: 20px;
        }
        
        .info-group h3 {
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
            margin-bottom: 15px;
            color: var(--primary-color);
        }
        
        .info-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .info-item {
            flex: 1;
            min-width: 200px;
        }
        
        .info-label {
            font-weight: bold;
            margin-bottom: 5px;
            color: var(--text-secondary);
        }
        
        .services-list {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 20px;
        }
        
        .service-badge {
            background-color: rgba(0, 119, 204, 0.2);
            color: var(--primary-color);
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .total-container {
            background-color: rgba(0, 119, 204, 0.1);
            border-radius: 5px;
            padding: 20px;
            margin-top: 30px;
        }
        
        .price-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .total-price {
            font-size: 24px;
            font-weight: bold;
            color: var(--accent-color);
            padding-top: 10px;
            margin-top: 10px;
            border-top: 1px dashed var(--border-color);
        }
        
        .booking-actions {
            display: flex;
            gap: 20px;
            margin-top: 30px;
        }
        
        /* صفحة اختيار المقاعد */
        .seat-map {
            background-color: var(--card-color);
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .airplane {
            margin: 40px auto;
            max-width: 600px;
            position: relative;
        }
        
        .airplane-header {
            width: 100%;
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        
        .cockpit {
            width: 150px;
            height: 80px;
            background-color: var(--primary-color);
            border-radius: 100% 100% 0 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        .cabin {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
        }
        
        .seat {
            background-color: #333;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .seat:hover {
            background-color: var(--primary-color);
            color: white;
        }
        
        .seat.occupied {
            background-color: #555;
            cursor: not-allowed;
        }
        
        .seat.selected {
            background-color: var(--accent-color);
            color: white;
        }
        
        .aisle {
            visibility: hidden;
        }
        
        .seat-legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 30px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            margin-left: 10px;
            border-radius: 3px;
        }
        
        .legend-available {
            background-color: #333;
        }
        
        .legend-selected {
            background-color: var(--accent-color);
        }
        
        .legend-occupied {
            background-color: #555;
        }
        
        /* النماذج المنبثقة */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            z-index: 1050;
            overflow-y: auto;
        }
        
        .modal-content {
            background-color: var(--card-color);
            margin: 50px auto;
            width: 90%;
            max-width: 800px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.5);
            animation: modalFade 0.3s;
        }
        
        @keyframes modalFade {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: bold;
        }
        
        .modal-close {
            font-size: 24px;
            cursor: pointer;
            color: var(--text-secondary);
            transition: color 0.3s;
        }
        
        .modal-close:hover {
            color: var(--accent-color);
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }
        
        /* تصميم الإشعارات */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 5px;
            color: white;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            z-index: 1100;
            animation: fadeInDown 0.5s;
            max-width: 350px;
        }
        
        .notification.success {
            background-color: var(--success-color);
        }
        
        .notification.error {
            background-color: var(--error-color);
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* تصميم المحمول */
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 36px;
            }
            
            .hero p {
                font-size: 16px;
            }
            
            .search-form {
                margin-top: -50px;
                padding: 20px;
            }
            
            .flight-card {
                flex-direction: column;
                gap: 15px;
            }
            
            .flight-details {
                flex-direction: column;
                gap: 25px;
            }
            
            .flight-line {
                transform: rotate(90deg);
                margin: 20px 0;
            }
            
            .price-info {
                align-items: center;
                width: 100%;
            }
            
            nav {
                flex-direction: column;
                gap: 15px;
            }
            
            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .nav-links li {
                margin: 0 10px 10px 0;
            }
            
            .modal-content {
                margin: 15px auto;
                width: 95%;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <nav>
                <div class="logo">
                    <i class="fas fa-plane"></i> FlyEasy
                </div>
                <ul class="nav-links">
                    <li><a href="#" class="active" onclick="showHome()">الرئيسية</a></li>
                    <li><a href="#" onclick="showSearchFlights()">البحث عن رحلات</a></li>
                    <li><a href="#" onclick="showBookingStatus()">حالة الحجز</a></li>
                    <li><a href="#about">من نحن</a></li>
                    <li><a href="#contact">اتصل بنا</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <div id="home-page">
        <section class="hero">
            <h1>سافر بسهولة وراحة مع FlyEasy</h1>
            <p>احجز رحلتك التالية بسرعة وبأفضل الأسعار إلى أكثر من 500 وجهة حول العالم</p>
        </section>

        <div class="container">
            <form class="search-form" id="flight-search-form" onsubmit="searchFlights(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label for="from-city">من</label>
                        <select id="from-city" required>
                            <option value="">اختر المدينة</option>
                            <?php 
                            $cities = getCities();
                            foreach ($cities as $city) {
                                echo '<option value="' . $city['id'] . '">' . $city['name'] . ' (' . $city['airport_code'] . ')</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="to-city">إلى</label>
                        <select id="to-city" required>
                            <option value="">اختر المدينة</option>
                            <?php 
                            foreach ($cities as $city) {
                                echo '<option value="' . $city['id'] . '">' . $city['name'] . ' (' . $city['airport_code'] . ')</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="departure-date">تاريخ المغادرة</label>
                        <input type="date" id="departure-date" required min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="travel-class">درجة السفر</label>
                        <select id="travel-class">
                            <option value="Economy">اقتصادية</option>
                            <option value="Business">رجال الأعمال</option>
                            <option value="First">الدرجة الأولى</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="airline">شركة الطيران (اختياري)</label>
                        <select id="airline">
                            <option value="">جميع شركات الطيران</option>
                            <?php 
                            $airlines = getAirlines();
                            foreach ($airlines as $airline) {
                                echo '<option value="' . $airline['id'] . '">' . $airline['name'] . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-block">البحث عن الرحلات</button>
            </form>

            <section class="section">
                <h2 class="section-title">وجهات شائعة</h2>
                <div class="popular-destinations">
                    <?php
                    $popularCities = getPopularCities();
                    $images = [
                        'https://images.unsplash.com/photo-1566240233465-a8d66666a9e9?w=600&auto=format&q=60',
                        'https://images.unsplash.com/photo-1488747279002-c8523379faaa?w=600&auto=format&q=60',
                        'https://images.unsplash.com/photo-1502700807168-484a3e7889d0?w=600&auto=format&q=60',
                        'https://images.unsplash.com/photo-1566650554919-44ec6bbe2518?w=600&auto=format&q=60',
                        'https://images.unsplash.com/photo-1582642018053-a56ed5d78fa5?w=600&auto=format&q=60'
                    ];
                    $prices = [450, 1200, 550, 700, 850];
                    
                    foreach ($popularCities as $index => $city) {
                        echo '
                        <div class="destination-card">
                            <img src="' . ($images[$index] ?? $images[0]) . '" alt="' . $city['name'] . '" class="destination-img">
                            <div class="destination-info">
                                <h3>' . $city['name'] . ', ' . $city['country'] . '</h3>
                                <p>' . $city['airport_name'] . ' (' . $city['airport_code'] . ')</p>
                                <div class="price">' . number_format($prices[$index] ?? 500, 2) . ' $ <span>ابتداءً من</span></div>
                                <button class="btn" onclick="setupSearch(' . $city['id'] . ')">احجز الآن</button>
                            </div>
                        </div>';
                    }
                    ?>
                </div>
            </section>

            <section class="section" id="about">
                <h2 class="section-title">لماذا تختارنا؟</h2>
                <div class="features">
                    <div class="feature">
                        <i class="fas fa-money-bill-wave"></i>
                        <h3>أفضل الأسعار</h3>
                        <p>نضمن لك أقل الأسعار للرحلات من وإلى جميع أنحاء العالم</p>
                    </div>
                    <div class="feature">
                        <i class="fas fa-headset"></i>
                        <h3>دعم متواصل</h3>
                        <p>فريق دعم متوفر على مدار الساعة للإجابة على استفساراتك</p>
                    </div>
                    <div class="feature">
                        <i class="fas fa-shield-alt"></i>
                        <h3>حجوزات آمنة</h3>
                        <p>معاملات آمنة وسرية تامة لبياناتك الشخصية</p>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <div id="search-results-page" style="display: none;">
        <div class="container">
            <h2 class="section-title">نتائج البحث</h2>
            <div id="search-summary" class="info-group" style="margin-bottom: 30px;"></div>
            <div id="flights-container" class="flights-container"></div>
        </div>
    </div>

    <div id="booking-page" style="display: none;">
        <div class="container">
            <h2 class="section-title">حجز الرحلة</h2>
            <div class="booking-details">
                <div id="flight-info" class="info-group"></div>
                
                <form id="booking-form" onsubmit="submitBooking(event)">
                    <input type="hidden" id="flight-id">
                    <input type="hidden" id="selected-seat" value="">
                    
                    <div class="info-group">
                        <h3>معلومات المسافر</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="passenger-name">الاسم الكامل</label>
                                <input type="text" id="passenger-name" required>
                            </div>
                            <div class="form-group">
                                <label for="passenger-email">البريد الإلكتروني</label>
                                <input type="email" id="passenger-email" required>
                            </div>
                            <div class="form-group">
                                <label for="passenger-phone">رقم الهاتف</label>
                                <input type="tel" id="passenger-phone">
                            </div>
                        </div>
                    </div>
                    
                    <div class="info-group">
                        <h3>اختيار المقعد</h3>
                        <button type="button" class="btn" onclick="openSeatModal()">اختر مقعدك</button>
                        <p id="selected-seat-display" style="margin-top: 10px;">لم يتم اختيار مقعد بعد</p>
                    </div>
                    
                    <div class="info-group">
                        <h3>الخدمات الإضافية</h3>
                        <div class="services-list" id="services-container">
                            <?php
                            $services = getAncillaryServices();
                            foreach ($services as $index => $service) {
                                if ($index < 6) { // نعرض فقط 6 خدمات للتبسيط
                                    echo '
                                    <div style="min-width: 250px; margin-bottom: 10px;">
                                        <input type="checkbox" id="service-' . $service['id'] . '" name="services[]" value="' . $service['id'] . '">
                                        <label for="service-' . $service['id'] . '">' . $service['service_name'] . ' - $' . number_format($service['price'], 2) . '</label>
                                    </div>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-block">تأكيد الحجز</button>
                </form>
            </div>
        </div>
    </div>

    <div id="booking-status-page" style="display: none;">
        <div class="container">
            <h2 class="section-title">استعلام عن حالة الحجز</h2>
            <div class="booking-details">
                <form id="booking-status-form" onsubmit="checkBookingStatus(event)">
                    <div class="form-group">
                        <label for="booking-reference">الرقم المرجعي للحجز</label>
                        <input type="text" id="booking-reference" required placeholder="أدخل الرقم المرجعي المكون من 6 أحرف">
                    </div>
                    <button type="submit" class="btn btn-block">البحث</button>
                </form>
            </div>
            <div id="booking-status-result" style="display: none;"></div>
        </div>
    </div>

    <div id="booking-confirmation-page" style="display: none;">
        <div class="container">
            <div class="booking-details">
                <div class="booking-header">
                    <div class="booking-title">تم تأكيد حجزك بنجاح!</div>
                    <div class="booking-reference" id="confirmation-reference"></div>
                </div>
                
                <div class="info-group">
                    <p>شكراً لك على الحجز من خلال FlyEasy. تم إرسال تفاصيل الحجز إلى بريدك الإلكتروني.</p>
                    <p>يمكنك الاستعلام عن حالة حجزك في أي وقت باستخدام الرقم المرجعي.</p>
                </div>
                
                <div id="confirmation-details"></div>
                
                <div class="booking-actions">
                    <button class="btn" onclick="showHome()">العودة إلى الرئيسية</button>
                    <button class="btn" onclick="printBooking()">طباعة تفاصيل الحجز</button>
                </div>
            </div>
        </div>
    </div>

    <!-- المودال الخاص باختيار المقاعد -->
    <div id="seat-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">اختر مقعدك</div>
                <span class="modal-close" onclick="closeSeatModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="seat-map">
                    <div class="airplane">
                        <div class="airplane-header">
                            <div class="cockpit">المقدمة</div>
                        </div>
                        <div class="cabin" id="seat-map-container">
                            <!-- سيتم إنشاء المقاعد عبر JavaScript -->
                        </div>
                    </div>
                    
                    <div class="seat-legend">
                        <div class="legend-item">
                            <div class="legend-color legend-available"></div>
                            <span>متاح</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color legend-selected"></div>
                            <span>محدد</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color legend-occupied"></div>
                            <span>مشغول</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn" onclick="confirmSeatSelection()">تأكيد</button>
            </div>
        </div>
    </div>

    <footer id="contact">
        <div class="container">
            <div class="footer-content">
                <div class="footer-column">
                    <h3>معلومات الاتصال</h3>
                    <p>بغداد، العراق</p>
                    <p>البريد الإلكتروني: info@flyeasy.com</p>
                    <p>الهاتف: 1234-567-789</p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
                <div class="footer-column">
                    <h3>روابط سريعة</h3>
                    <ul>
                        <li><a href="#">الرئيسية</a></li>
                        <li><a href="#">البحث عن رحلات</a></li>
                        <li><a href="#">حالة الحجز</a></li>
                        <li><a href="#">من نحن</a></li>
                        <li><a href="#">الشروط والأحكام</a></li>
                        <li><a href="#">سياسة الخصوصية</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3>المساعدة والدعم</h3>
                    <ul>
                        <li><a href="#">الأسئلة الشائعة</a></li>
                        <li><a href="#">سياسة الإلغاء</a></li>
                        <li><a href="#">اتصل بنا</a></li>
                        <li><a href="#">التعليمات</a></li>
                    </ul>
                </div>
                <div class="footer-column">
                    <h3>النشرة الإخبارية</h3>
                    <p>اشترك للحصول على أحدث العروض والخصومات</p>
                    <form class="newsletter-form">
                        <input type="email" placeholder="البريد الإلكتروني" required>
                        <button type="submit" class="btn">اشتراك</button>
                    </form>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 FlyEasy - جميع الحقوق محفوظة</p>
            </div>
        </div>
    </footer>

    <script>
        // الوظائف العامة
        function showPage(pageId) {
            document.getElementById('home-page').style.display = 'none';
            document.getElementById('search-results-page').style.display = 'none';
            document.getElementById('booking-page').style.display = 'none';
            document.getElementById('booking-status-page').style.display = 'none';
            document.getElementById('booking-confirmation-page').style.display = 'none';
            
            document.getElementById(pageId).style.display = 'block';
            window.scrollTo(0, 0);
        }
        
        function showHome() {
            showPage('home-page');
        }
        
        function showSearchFlights() {
            showPage('search-results-page');
        }
        
        function showBookingStatus() {
            showPage('booking-status-page');
        }
        
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => {
                    document.body.removeChild(notification);
                }, 500);
            }, 3000);
        }
        
        // وظائف البحث عن الرحلات
        function setupSearch(cityId) {
            document.getElementById('to-city').value = cityId;
            document.getElementById('from-city').value = 1; // بغداد كمدينة افتراضية للانطلاق
            
            // التمرير إلى نموذج البحث
            const searchForm = document.getElementById('flight-search-form');
            searchForm.scrollIntoView({ behavior: 'smooth' });
        }
        
        function searchFlights(event) {
            event.preventDefault();
            
            const fromCityId = document.getElementById('from-city').value;
            const toCityId = document.getElementById('to-city').value;
            const departureDate = document.getElementById('departure-date').value;
            const airlineId = document.getElementById('airline').value;
            const travelClass = document.getElementById('travel-class').value;
            
            // التحقق من صحة المدخلات
            if (fromCityId === toCityId) {
                showNotification('لا يمكن أن تكون مدينة المغادرة والوصول متطابقة', 'error');
                return;
            }
            
            // عرض رسالة تحميل
            const flightsContainer = document.getElementById('flights-container');
            flightsContainer.innerHTML = '<div style="text-align: center; padding: 30px;"><i class="fas fa-spinner fa-spin fa-3x"></i><p style="margin-top: 15px;">جاري البحث عن الرحلات...</p></div>';
            
            // عرض صفحة النتائج
            showPage('search-results-page');
            
            // عرض ملخص البحث
            const fromCityText = document.getElementById('from-city').options[document.getElementById('from-city').selectedIndex].text;
            const toCityText = document.getElementById('to-city').options[document.getElementById('to-city').selectedIndex].text;
            const formattedDate = new Date(departureDate).toLocaleDateString('ar-IQ', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            
            document.getElementById('search-summary').innerHTML = `
                <h3>تفاصيل الرحلة</h3>
                <div class="info-row">
                    <div class="info-item">
                        <div class="info-label">من</div>
                        <div>${fromCityText}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">إلى</div>
                        <div>${toCityText}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">تاريخ المغادرة</div>
                        <div>${formattedDate}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">درجة السفر</div>
                        <div>${travelClass === 'Economy' ? 'اقتصادية' : (travelClass === 'Business' ? 'رجال الأعمال' : 'الدرجة الأولى')}</div>
                    </div>
                </div>
            `;
            
            // إرسال الطلب إلى الخادم للبحث عن الرحلات
            fetch(`?action=search_flights`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    fromCityId,
                    toCityId,
                    departureDate,
                    airlineId: airlineId || null,
                    travelClass
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    showNotification(data.error, 'error');
                    flightsContainer.innerHTML = `<div style="text-align: center; padding: 30px;">${data.error}</div>`;
                    return;
                }
                
                if (data.flights && data.flights.length > 0) {
                    renderFlights(data.flights, travelClass);
                } else {
                    flightsContainer.innerHTML = `
                        <div style="text-align: center; padding: 30px;">
                            <i class="fas fa-exclamation-circle fa-3x" style="color: var(--text-secondary);"></i>
                            <p style="margin-top: 15px;">لم يتم العثور على رحلات متطابقة. يرجى تغيير معايير البحث.</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('حدث خطأ أثناء البحث عن الرحلات', 'error');
                flightsContainer.innerHTML = `<div style="text-align: center; padding: 30px;">حدث خطأ أثناء البحث. يرجى المحاولة مرة أخرى.</div>`;
            });
        }
        
        function renderFlights(flights, travelClass) {
            const flightsContainer = document.getElementById('flights-container');
            
            if (flights.length === 0) {
                flightsContainer.innerHTML = `
                    <div style="text-align: center; padding: 30px;">
                        <i class="fas fa-exclamation-circle fa-3x" style="color: var(--text-secondary);"></i>
                        <p style="margin-top: 15px;">لم يتم العثور على رحلات متطابقة. يرجى تغيير معايير البحث.</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            
            flights.forEach(flight => {
                // تنسيق البيانات
                const airlineCode = flight.airline_name.substring(0, 2).toUpperCase();
                const price = parseFloat(flight.base_price).toFixed(2);
                
                html += `
                    <div class="flight-card">
                        <div class="airline-info">
                            <div class="airline-logo">
                                <i class="fas fa-plane"></i>
                            </div>
                            <div class="airline-details">
                                <h3>${flight.airline_name}</h3>
                                <p>رحلة ${flight.flight_number}</p>
                            </div>
                        </div>
                        <div class="flight-details">
                            <div class="departure">
                                <div class="time">${flight.departure_time}</div>
                                <div class="airport-code">${flight.from_code}</div>
                                <div>${flight.from_city}</div>
                            </div>
                            <div class="flight-duration">
                                <div class="duration">${Math.floor(flight.flight_duration / 60)}h ${flight.flight_duration % 60}m</div>
                                <div class="flight-line">
                                    <hr>
                                    <i class="fas fa-plane"></i>
                                    <hr>
                                </div>
                                <div>مباشر</div>
                            </div>
                            <div class="arrival">
                                <div class="time">${flight.arrival_time}</div>
                                <div class="airport-code">${flight.to_code}</div>
                                <div>${flight.to_city}</div>
                            </div>
                        </div>
                        <div class="price-info">
                            <div class="flight-price">$${price}</div>
                            <button class="btn" onclick="selectFlight(${flight.id})">اختر</button>
                        </div>
                    </div>
                `;
            });
            
            flightsContainer.innerHTML = html;
        }
        
        // وظائف الحجز
        function selectFlight(flightId) {
            // عرض صفحة الحجز وتحميل معلومات الرحلة
            showPage('booking-page');
            
            // تخزين معرف الرحلة
            document.getElementById('flight-id').value = flightId;
            
            // عرض صفحة التحميل
            document.getElementById('flight-info').innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p style="margin-top: 10px;">جاري تحميل معلومات الرحلة...</p>
                </div>
            `;
            
            // جلب تفاصيل الرحلة من الخادم
            fetch(`?action=get_flight&id=${flightId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        showNotification(data.error, 'error');
                        return;
                    }
                    
                    const flight = data;
                    
                    // عرض تفاصيل الرحلة
                    document.getElementById('flight-info').innerHTML = `
                        <h3>تفاصيل الرحلة</h3>
                        <div class="info-row">
                            <div class="info-item">
                                <div class="info-label">شركة الطيران</div>
                                <div>${flight.airline_name}</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">رقم الرحلة</div>
                                <div>${flight.flight_number}</div>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-item">
                                <div class="info-label">من</div>
                                <div>${flight.from_city} (${flight.from_code})</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">إلى</div>
                                <div>${flight.to_city} (${flight.to_code})</div>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-item">
                                <div class="info-label">وقت المغادرة</div>
                                <div>${flight.departure_time}</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">وقت الوصول</div>
                                <div>${flight.arrival_time}</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">مدة الرحلة</div>
                                <div>${Math.floor(flight.flight_duration / 60)}h ${flight.flight_duration % 60}m</div>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="info-item">
                                <div class="info-label">درجة السفر</div>
                                <div>${flight.travel_class === 'Economy' ? 'اقتصادية' : (flight.travel_class === 'Business' ? 'رجال الأعمال' : 'الدرجة الأولى')}</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">السعر الأساسي</div>
                                <div>$${parseFloat(flight.base_price).toFixed(2)}</div>
                            </div>
                        </div>
                    `;
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('حدث خطأ أثناء تحميل معلومات الرحلة', 'error');
                });
        }
        
        function openSeatModal() {
            document.getElementById('seat-modal').style.display = 'block';
            generateSeatMap();
        }
        
        function closeSeatModal() {
            document.getElementById('seat-modal').style.display = 'none';
        }
        
        function generateSeatMap() {
            const seatMapContainer = document.getElementById('seat-map-container');
            const rows = 10;
            const cols = ['A', 'B', 'C', '', 'D', 'E', 'F'];
            let html = '';
            
            // إنشاء تخطيط افتراضي للمقاعد
            for (let i = 1; i <= rows; i++) {
                for (let j = 0; j < cols.length; j++) {
                    if (cols[j] === '') {
                        html += `<div class="aisle"></div>`;
                    } else {
                        const seatId = `${i}${cols[j]}`;
                        // تحديد بعض المقاعد عشوائياً كمشغولة للعرض التوضيحي
                        const isOccupied = Math.random() < 0.3;
                        html += `<div class="seat ${isOccupied ? 'occupied' : ''}" id="seat-${seatId}" onclick="selectSeat('${seatId}', this)">${seatId}</div>`;
                    }
                }
            }
            
            seatMapContainer.innerHTML = html;
            
            // إظهار المقعد المحدد حالياً (إن وجد)
            const selectedSeat = document.getElementById('selected-seat').value;
            if (selectedSeat) {
                const seatElement = document.getElementById(`seat-${selectedSeat}`);
                if (seatElement && !seatElement.classList.contains('occupied')) {
                    seatElement.classList.add('selected');
                }
            }
        }
        
        function selectSeat(seatId, element) {
            // التحقق من أن المقعد غير مشغول
            if (element.classList.contains('occupied')) {
                return;
            }
            
            // إزالة التحديد من المقعد السابق
            const selectedSeats = document.querySelectorAll('.seat.selected');
            selectedSeats.forEach(seat => seat.classList.remove('selected'));
            
            // تحديد المقعد الجديد
            element.classList.add('selected');
            document.getElementById('selected-seat').value = seatId;
        }
        
        function confirmSeatSelection() {
            const selectedSeat = document.getElementById('selected-seat').value;
            if (!selectedSeat) {
                showNotification('يرجى اختيار مقعد أولاً', 'error');
                return;
            }
            
            // تحديث عرض المقعد المحدد
            document.getElementById('selected-seat-display').textContent = `المقعد المحدد: ${selectedSeat}`;
            
            // إغلاق المودال
            closeSeatModal();
        }
        
        function submitBooking(event) {
            event.preventDefault();
            
            const flightId = document.getElementById('flight-id').value;
            const passengerName = document.getElementById('passenger-name').value;
            const passengerEmail = document.getElementById('passenger-email').value;
            const passengerPhone = document.getElementById('passenger-phone').value;
            const selectedSeat = document.getElementById('selected-seat').value;
            const travelClass = 'Economy'; // يمكن تعديله ليكون قابل للاختيار
            
            // جمع الخدمات الإضافية المحددة
            const selectedServices = [];
            document.querySelectorAll('input[name="services[]"]:checked').forEach(checkbox => {
                selectedServices.push(parseInt(checkbox.value));
            });
            
            // التحقق من صحة المدخلات
            if (!passengerName || !passengerEmail || !flightId) {
                showNotification('يرجى ملء جميع الحقول المطلوبة', 'error');
                return;
            }
            
            if (!selectedSeat) {
                showNotification('يرجى اختيار مقعد', 'error');
                return;
            }
            
            // عرض رسالة تحميل
            const bookingForm = document.getElementById('booking-form');
            const submitButton = bookingForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري معالجة الحجز...';
            
            // إرسال الطلب إلى الخادم
            fetch('?action=book_flight', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    flightId,
                    passengerName,
                    passengerEmail,
                    passengerPhone,
                    seatNumber: selectedSeat,
                    travelClass,
                    selectedServices
                })
            })
            .then(response => response.json())
            .then(data => {
                submitButton.disabled = false;
                submitButton.innerHTML = 'تأكيد الحجز';
                
                if (data.error) {
                    showNotification(data.error, 'error');
                    return;
                }
                
                // عرض صفحة تأكيد الحجز
                showBookingConfirmation(data.booking);
            })
            .catch(error => {
                console.error('Error:', error);
                submitButton.disabled = false;
                submitButton.innerHTML = 'تأكيد الحجز';
                showNotification('حدث خطأ أثناء معالجة الحجز', 'error');
            });
        }
        
        function showBookingConfirmation(booking) {
            // تحديث الرقم المرجعي للحجز
            document.getElementById('confirmation-reference').textContent = booking.booking_reference;
            
            // تحميل تفاصيل الحجز
            fetch(`?action=get_booking&reference=${booking.booking_reference}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        showNotification(data.error, 'error');
                        return;
                    }
                    
                    const bookingDetails = data.booking;
                    
                    // عرض تفاصيل الحجز
                    document.getElementById('confirmation-details').innerHTML = `
                        <div class="info-group">
                            <h3>تفاصيل الرحلة</h3>
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">شركة الطيران</div>
                                    <div>${bookingDetails.airline_name}</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">رقم الرحلة</div>
                                    <div>${bookingDetails.flight_number}</div>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">من</div>
                                    <div>${bookingDetails.from_city} (${bookingDetails.from_code})</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">إلى</div>
                                    <div>${bookingDetails.to_city} (${bookingDetails.to_code})</div>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">وقت المغادرة</div>
                                    <div>${bookingDetails.departure_time}</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">وقت الوصول</div>
                                    <div>${bookingDetails.arrival_time}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="info-group">
                            <h3>معلومات المسافر</h3>
                            <div class="info-row">
                                <div class="info-item">
                                    <div class="info-label">الاسم</div>
                                    <div>${bookingDetails.passenger_name}</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">البريد الإلكتروني</div>
                                    <div>${bookingDetails.passenger_email}</div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">رقم المقعد</div>
                                    <div>${bookingDetails.seat_number}</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="total-container">
                            <div class="price-row">
                                <div>السعر الأساسي</div>
                                <div>$${parseFloat(bookingDetails.base_price).toFixed(2)}</div>
                            </div>
                            <div class="price-row">
                                <div>الضرائب والرسوم</div>
                                <div>$${parseFloat(bookingDetails.taxes_fees).toFixed(2)}</div>
                            </div>
                            <div class="price-row">
                                <div>الخدمات الإضافية</div>
                                <div>$${parseFloat(bookingDetails.ancillary_services_price).toFixed(2)}</div>
                            </div>
                            <div class="price-row total-price">
                                <div>المجموع الكلي</div>
                                <div>$${parseFloat(bookingDetails.final_price).toFixed(2)}</div>
                            </div>
                        </div>
                    `;
                    
                    // عرض صفحة التأكيد
                    showPage('booking-confirmation-page');
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('حدث خطأ أثناء تحميل تفاصيل الحجز', 'error');
                });
        }
        
        function checkBookingStatus(event) {
            event.preventDefault();
            
            const bookingReference = document.getElementById('booking-reference').value.trim().toUpperCase();
            
            if (!bookingReference) {
                showNotification('يرجى إدخال الرقم المرجعي للحجز', 'error');
                return;
            }
            
            // عرض رسالة تحميل
            const statusForm = document.getElementById('booking-status-form');
            const submitButton = statusForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> جاري البحث...';
            
            // جلب حالة الحجز من الخادم
            fetch(`?action=get_booking&reference=${bookingReference}`)
                .then(response => response.json())
                .then(data => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'البحث';
                    
                    const resultContainer = document.getElementById('booking-status-result');
                    
                    if (data.error) {
                        resultContainer.innerHTML = `
                            <div style="text-align: center; padding: 30px; background-color: var(--card-color); border-radius: 10px; margin-top: 20px;">
                                <i class="fas fa-exclamation-circle fa-3x" style="color: var(--error-color);"></i>
                                <p style="margin-top: 15px;">${data.error}</p>
                            </div>
                        `;
                        resultContainer.style.display = 'block';
                        return;
                    }
                    
                    const booking = data.booking;
                    
                    resultContainer.innerHTML = `
                        <div class="booking-details" style="margin-top: 30px;">
                            <div class="booking-header">
                                <div class="booking-title">تفاصيل الحجز</div>
                                <div class="booking-reference">${booking.booking_reference}</div>
                            </div>
                            
                            <div class="info-group">
                                <h3>تفاصيل الرحلة</h3>
                                <div class="info-row">
                                    <div class="info-item">
                                        <div class="info-label">شركة الطيران</div>
                                        <div>${booking.airline_name}</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">رقم الرحلة</div>
                                        <div>${booking.flight_number}</div>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-item">
                                        <div class="info-label">من</div>
                                        <div>${booking.from_city} (${booking.from_code})</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">إلى</div>
                                        <div>${booking.to_city} (${booking.to_code})</div>
                                    </div>
                                </div>
                                <div class="info-row">
                                    <div class="info-item">
                                        <div class="info-label">وقت المغادرة</div>
                                        <div>${booking.departure_time}</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">وقت الوصول</div>
                                        <div>${booking.arrival_time}</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="info-group">
                                <h3>معلومات المسافر</h3>
                                <div class="info-row">
                                    <div class="info-item">
                                        <div class="info-label">الاسم</div>
                                        <div>${booking.passenger_name}</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">رقم المقعد</div>
                                        <div>${booking.seat_number}</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">حالة الحجز</div>
                                        <div style="color: ${booking.booking_status === 'Confirmed' ? 'var(--success-color)' : 'var(--error-color)'}">
                                            ${booking.booking_status === 'Confirmed' ? 'مؤكد' : booking.booking_status}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="total-container">
                                <div class="price-row">
                                    <div>السعر الإجمالي</div>
                                    <div>$${parseFloat(booking.final_price).toFixed(2)}</div>
                                </div>
                                <div class="price-row">
                                    <div>حالة الدفع</div>
                                    <div style="color: ${booking.payment_status === 'Paid' ? 'var(--success-color)' : 'var(--error-color)'}">
                                        ${booking.payment_status === 'Paid' ? 'مدفوع' : 'غير مدفوع'}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    resultContainer.style.display = 'block';
                })
                .catch(error => {
                    console.error('Error:', error);
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'البحث';
                    showNotification('حدث خطأ أثناء البحث عن الحجز', 'error');
                });
        }
        
        function printBooking() {
            window.print();
        }
        
        // تعيين التاريخ الافتراضي لحقل تاريخ المغادرة للتاريخ الحالي
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date();
            const tomorrow = new Date(today);
            tomorrow.setDate(tomorrow.getDate() + 1);
            
            const formattedDate = tomorrow.toISOString().substr(0, 10);
            document.getElementById('departure-date').value = formattedDate;
        });
    </script>
</body>
</html>

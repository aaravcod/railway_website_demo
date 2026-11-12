<?php
declare(strict_types=1);

const DATA_FILE = __DIR__ . '/data.json';
const ROLES = [
    'customer' => ['book:create', 'booking:read:self', 'booking:cancel:self'],
    'staff' => ['booking:read:any'],
    'admin' => ['booking:read:any', 'user:read', 'user:role:update'],
];

ini_set('session.use_strict_mode', '1');
session_name('raahi_session');
session_set_cookie_params(['lifetime' => 43200, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

final class ApiResponse extends RuntimeException {
    public function __construct(public int $status, public array $payload) { parent::__construct(); }
}
function respond(int $status, array $body): never {
    throw new ApiResponse($status, $body);
}
function emitResponse(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) respond(400, ['error' => 'Invalid JSON request.']);
    return $data;
}
function seed(): array {
    $trains = [
        ['id'=>'rajdhani-12951','name'=>'Mumbai Rajdhani','number'=>'12951','from'=>'Mumbai Central','to'=>'New Delhi','depart'=>'17:00','arrive'=>'08:32','duration'=>'15h 32m','fare'=>2840],
        ['id'=>'shatabdi-12009','name'=>'Shatabdi Express','number'=>'12009','from'=>'Mumbai Central','to'=>'Ahmedabad','depart'=>'06:25','arrive'=>'13:10','duration'=>'6h 45m','fare'=>1120],
        ['id'=>'duronto-12267','name'=>'Duronto Express','number'=>'12267','from'=>'Mumbai Central','to'=>'New Delhi','depart'=>'23:00','arrive'=>'16:20','duration'=>'17h 20m','fare'=>2310],
        ['id'=>'deccan-12123','name'=>'Deccan Queen','number'=>'12123','from'=>'Mumbai CSMT','to'=>'Pune','depart'=>'17:10','arrive'=>'20:25','duration'=>'3h 15m','fare'=>490],
    ];
    $seats = [];
    for ($i=0; $i<40; $i++) $seats[] = ['id'=>(string)(intdiv($i,4)+1) . 'ABCD'[$i%4], 'type'=>$i<24?'Window & aisle':'Standard'];
    return ['users'=>[
        ['id'=>bin2hex(random_bytes(8)),'name'=>'Demo Admin','email'=>'admin@raildemo.test','password'=>password_hash('RailDemo!2026', PASSWORD_DEFAULT),'role'=>'admin'],
        ['id'=>bin2hex(random_bytes(8)),'name'=>'Demo Staff','email'=>'staff@raildemo.test','password'=>password_hash('RailDemo!2026', PASSWORD_DEFAULT),'role'=>'staff'],
    ],'trains'=>$trains,'seats'=>$seats,'bookings'=>[],'trips'=>[]];
}
function ensureSampleRecords(array &$db): void {
    if (($db['demoSeedVersion'] ?? 0) >= 1) return;
    $passenger=null;
    foreach($db['users'] as $u) if($u['email']==='passenger@raildemo.test') $passenger=$u;
    if (!$passenger) {
        $passenger=['id'=>bin2hex(random_bytes(8)),'name'=>'Aanya Mehta','email'=>'passenger@raildemo.test','password'=>password_hash('RailDemo!2026',PASSWORD_DEFAULT),'role'=>'customer'];
        $db['users'][]=$passenger;
    }
    $samples=[['rajdhani-12951',2,'1A','confirmed'],['shatabdi-12009',3,'4D','confirmed'],['duronto-12267',3,'2B','cancelled'],['deccan-12123',5,'5C','confirmed']];
    foreach($samples as $i=>$sample) {
        [$trainId,$days,$seatId,$status]=$sample; $train=null;
        foreach($db['trains'] as $t) if($t['id']===$trainId) $train=$t;
        if(!$train) continue;
        $date=date('Y-m-d',strtotime('+'.$days.' days')); $key=tripKey($trainId,$date); $db['trips'][$key]??=['booked'=>[]];
        if($status==='confirmed'&&!in_array($seatId,$db['trips'][$key]['booked'],true)) $db['trips'][$key]['booked'][]=$seatId;
        $db['bookings'][]=['id'=>'RDDEMO'.($i+1),'userId'=>$passenger['id'],'trainId'=>$trainId,'trainName'=>$train['name'],'trainNumber'=>$train['number'],'from'=>$train['from'],'to'=>$train['to'],'date'=>$date,'depart'=>$train['depart'],'arrive'=>$train['arrive'],'seatId'=>$seatId,'fare'=>$train['fare'],'status'=>$status,'createdAt'=>date(DATE_ATOM)];
    }
    $db['demoSeedVersion']=1;
}
function withDb(callable $action): mixed {
    $fp = fopen(DATA_FILE, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) respond(500, ['error'=>'Unable to open demo data.']);
    rewind($fp); $raw = stream_get_contents($fp);
    $db = $raw ? json_decode($raw, true) : null;
    if (!is_array($db)) $db = seed();
    ensureSampleRecords($db);
    $status=200; $payload=[];
    try { $action($db); }
    catch (ApiResponse $response) { $status=$response->status; $payload=$response->payload; }
    rewind($fp); ftruncate($fp, 0); fwrite($fp, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    emitResponse($status,$payload);
}
function safeUser(array $user): array { unset($user['password']); return $user; }
function currentUser(array $db): ?array {
    $id = $_SESSION['user_id'] ?? null;
    foreach ($db['users'] as $user) if ($user['id'] === $id) return $user;
    return null;
}
function can(?array $user, string $permission): bool { return $user !== null && in_array($permission, ROLES[$user['role']] ?? [], true); }
function tripKey(string $trainId, string $date): string { return $trainId . ':' . $date; }
function validDate(string $date): bool { $d=DateTime::createFromFormat('!Y-m-d',$date); return $d && $d->format('Y-m-d')===$date && $date >= date('Y-m-d'); }
function api(array &$db): never {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET'; $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path === '/api/auth/signup' && $method === 'POST') {
        $in=input(); $name=trim((string)($in['name']??'')); $email=strtolower(trim((string)($in['email']??''))); $password=(string)($in['password']??'');
        if (strlen($name)<2 || strlen($name)>140 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<10 || strlen($password)>128) respond(400,['error'=>'Enter a name, valid email, and password of at least 10 characters.']);
        foreach($db['users'] as $u) if($u['email']===$email) respond(409,['error'=>'An account with that email already exists.']);
        $user=['id'=>bin2hex(random_bytes(12)),'name'=>$name,'email'=>$email,'password'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'customer','createdAt'=>date(DATE_ATOM)]; $db['users'][]=$user; $_SESSION['user_id']=$user['id']; respond(201,['user'=>safeUser($user)]);
    }
    if ($path === '/api/auth/login' && $method === 'POST') {
        $in=input(); $email=strtolower(trim((string)($in['email']??''))); $password=(string)($in['password']??'');
        foreach($db['users'] as $u) if($u['email']===$email && password_verify($password,$u['password'])) { session_regenerate_id(true); $_SESSION['user_id']=$u['id']; respond(200,['user'=>safeUser($u)]); }
        respond(401,['error'=>'Email or password is incorrect.']);
    }
    if ($path === '/api/auth/logout' && $method === 'POST') { $_SESSION=[]; session_destroy(); respond(200,['ok'=>true]); }
    if ($path === '/api/auth/me' && $method === 'GET') { $u=currentUser($db); respond(200,['user'=>$u?safeUser($u):null]); }
    if ($path==='/api/admin/dashboard'&&$method==='GET') {
        $user=currentUser($db); if(!can($user,'user:read'))respond($user?403:401,['error'=>'Admin access required.']);
        $records=[]; foreach($db['bookings'] as $b) { $b['passenger']='Unknown passenger'; $b['email']=''; foreach($db['users'] as $u)if($u['id']===$b['userId']){$b['passenger']=$u['name'];$b['email']=$u['email'];break;} $records[]=$b; }
        usort($records,fn($a,$b)=>strcmp($b['date'],$a['date']));
        $confirmed=array_filter($records,fn($b)=>$b['status']==='confirmed');
        respond(200,['stats'=>['bookings'=>count($records),'confirmed'=>count($confirmed),'revenue'=>array_sum(array_column($confirmed,'fare')),'passengers'=>count(array_filter($db['users'],fn($u)=>$u['role']==='customer')),'trains'=>count($db['trains'])],'bookings'=>$records,'users'=>array_map('safeUser',$db['users']),'trains'=>$db['trains']]);
    }
    if ($path === '/api/trains' && $method === 'GET') {
        $from=strtolower($_GET['from']??''); $to=strtolower($_GET['to']??''); $date=$_GET['date']??date('Y-m-d',strtotime('+1 day'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) respond(400,['error'=>'Choose a valid travel date.']);
        $trains=[]; foreach($db['trains'] as $t) if((!$from||str_contains(strtolower($t['from']),$from))&&(!$to||str_contains(strtolower($t['to']),$to))) { $key=tripKey($t['id'],$date); $taken=$db['trips'][$key]['booked']??[]; $trains[]=$t+['date'=>$date,'available'=>count($db['seats'])-count($taken)]; }
        respond(200,['trains'=>$trains]);
    }
    if (preg_match('#^/api/trains/([^/]+)/seats$#',$path,$m) && $method==='GET') {
        $id=urldecode($m[1]); $date=$_GET['date']??date('Y-m-d',strtotime('+1 day')); $train=null; foreach($db['trains'] as $t) if($t['id']===$id)$train=$t;
        if(!$train)respond(404,['error'=>'Train not found.']); if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))respond(400,['error'=>'Choose a valid travel date.']); $taken=$db['trips'][tripKey($id,$date)]['booked']??[]; $seats=array_map(fn($s)=>$s+['available'=>!in_array($s['id'],$taken,true)],$db['seats']); respond(200,['seats'=>$seats]);
    }
    if ($path==='/api/bookings' && $method==='POST') {
        $user=currentUser($db); if(!can($user,'book:create'))respond($user?403:401,['error'=>$user?'Your role cannot create bookings.':'Sign in to book a seat.']); $in=input(); $train=null; foreach($db['trains'] as $t)if($t['id']===($in['trainId']??''))$train=$t; $date=(string)($in['date']??''); $seat=(string)($in['seatId']??'');
        if(!$train||!validDate($date)||!in_array($seat,array_column($db['seats'],'id'),true))respond(400,['error'=>'Choose a valid train, travel date, and seat.']); $key=tripKey($train['id'],$date); $db['trips'][$key]??=['booked'=>[]]; if(in_array($seat,$db['trips'][$key]['booked'],true))respond(409,['error'=>'That seat was just booked. Choose another seat.']);
        $db['trips'][$key]['booked'][]=$seat; $booking=['id'=>'RD'.strtoupper(bin2hex(random_bytes(4))),'userId'=>$user['id'],'trainId'=>$train['id'],'trainName'=>$train['name'],'trainNumber'=>$train['number'],'from'=>$train['from'],'to'=>$train['to'],'date'=>$date,'depart'=>$train['depart'],'arrive'=>$train['arrive'],'seatId'=>$seat,'fare'=>$train['fare'],'status'=>'confirmed','createdAt'=>date(DATE_ATOM)]; $db['bookings'][]=$booking; respond(201,['booking'=>$booking]);
    }
    if ($path==='/api/bookings' && $method==='GET') {
        $user=currentUser($db); if(!$user)respond(401,['error'=>'Sign in to view bookings.']); if(!can($user,'booking:read:self')&&!can($user,'booking:read:any'))respond(403,['error'=>'Your role cannot view bookings.']); $items=[]; foreach($db['bookings'] as $b) if(can($user,'booking:read:any')||$b['userId']===$user['id']) { foreach($db['users'] as $u)if($u['id']===$b['userId'])$b['passenger']=$u['name']; $items[]=$b; } respond(200,['bookings'=>$items]);
    }
    if (preg_match('#^/api/bookings/([^/]+)/cancel$#',$path,$m)&&$method==='POST') {
        $user=currentUser($db); if(!$user)respond(401,['error'=>'Sign in to manage bookings.']); foreach($db['bookings'] as &$b)if($b['id']===$m[1]) { if(!can($user,'booking:cancel:self')||$b['userId']!==$user['id'])respond(403,['error'=>'You can only cancel your own booking.']); if($b['status']!=='confirmed')respond(409,['error'=>'This booking is already cancelled.']); $b['status']='cancelled'; $key=tripKey($b['trainId'],$b['date']); $db['trips'][$key]['booked']=array_values(array_diff($db['trips'][$key]['booked'],[$b['seatId']])); $out=$b; unset($b); respond(200,['booking'=>$out]); } unset($b); respond(404,['error'=>'Booking not found.']);
    }
    if ($path==='/api/admin/users'&&$method==='GET') { $user=currentUser($db); if(!can($user,'user:read'))respond($user?403:401,['error'=>'Admin access required.']); respond(200,['users'=>array_map('safeUser',$db['users'])]); }
    if (preg_match('#^/api/admin/users/([^/]+)/role$#',$path,$m)&&$method==='PATCH') { $user=currentUser($db); if(!can($user,'user:role:update'))respond($user?403:401,['error'=>'Admin access required.']); $in=input(); if(!in_array($in['role']??'', ['customer','staff','admin'],true))respond(400,['error'=>'Role must be customer, staff, or admin.']); foreach($db['users'] as &$target)if($target['id']===$m[1]) { if($target['id']===$user['id']&&$in['role']!=='admin')respond(400,['error'=>'You cannot remove your own admin role.']); $target['role']=$in['role']; $safe=safeUser($target); unset($target); respond(200,['user'=>$safe]); } unset($target); respond(404,['error'=>'User not found.']); }
    respond(404,['error'=>'API route not found.']);
}

$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
if(str_starts_with($path,'/api/')) withDb(fn(&$db)=>api($db));
readfile(__DIR__.'/public/index.html');

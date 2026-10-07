<?php
session_start();
$env = fn($k, $d) => getenv($k) !== false && getenv($k) !== '' ? getenv($k) : $d;
try {
    $db = new PDO('mysql:host=' . $env('DB_HOST', $env('MYSQLHOST', '127.0.0.1')) . ';port=' . $env('DB_PORT', $env('MYSQLPORT', 3306)) . ';dbname=' . $env('DB_NAME', $env('MYSQLDATABASE', 'smart_sms')) . ';charset=utf8mb4',
        $env('DB_USER', $env('MYSQLUSER', 'root')), $env('DB_PASS', $env('MYSQLPASSWORD', '')),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
} catch (Exception $x) { http_response_code(500); die('Database connection failed. Check DB_* variables.'); }

if (!$db->query("SHOW TABLES LIKE 'users'")->fetch()) {
    $sql = preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/../schema.sql'));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $s) $db->exec($s);
}

function q($s, $a = []) { global $db; $st = $db->prepare($s); $st->execute($a); return $st; }
function rows($s, $a = []) { return q($s, $a)->fetchAll(); }
function one($s, $a = []) { return q($s, $a)->fetchColumn(); }
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }
function me() { return $_SESSION['u'] ?? null; }
function go($p, $m = '') { if ($m) $_SESSION['m'] = $m; header('Location: ?p=' . $p); exit; }
function need(...$r) { $u = me(); if (!$u) go('login'); if ($r && !in_array($u['role'], $r)) go('dash', 'Access denied'); return $u; }
function csrf() { return $_SESSION['c'] ??= bin2hex(random_bytes(16)); }
function f() { return '<input type=hidden name=c value="' . csrf() . '">'; }
function clsel($sel, $name = 'cl', $auto = true) {
    $o = ''; foreach ([5, 6, 7, 8, 9, 10] as $c) $o .= '<option ' . ($c == $sel ? 'selected' : '') . '>' . $c . '</option>';
    return '<select name=' . $name . ($auto ? ' onchange="this.form.submit()"' : '') . '>' . $o . '</select>';
}
function pick($p, $cl, $extra = '') { return '<form method=get class=card><input type=hidden name=p value=' . $p . '><div class=row>' . clsel($cl) . $extra . '</div></form>'; }
function students($cl) { return rows("SELECT id,name,roll_no FROM users WHERE role='student' AND class_name=? ORDER BY roll_no,name", [$cl]); }
function tbl($h, $r) {
    $o = '<div class="card tw"><table><tr>'; foreach ($h as $x) $o .= '<th>' . $x . '</th>'; $o .= '</tr>';
    foreach ($r as $row) { $o .= '<tr>'; foreach ($row as $c) $o .= '<td>' . $c . '</td>'; $o .= '</tr>'; }
    return $o . ($r ? '' : '<tr><td colspan=' . count($h) . ' class=muted>No records</td></tr>') . '</table></div>';
}
function stat($i, $v, $l) { return '<div class=stat><div class=ic>' . $i . '</div><div><b>' . e($v) . '</b><small>' . e($l) . '</small></div></div>'; }
function view($t, $b) {
    $u = me(); $m = $_SESSION['m'] ?? ''; unset($_SESSION['m']);
    echo '<!doctype html><html><head><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1"><title>' . e($t) . ' - Smart SMS</title><link rel=stylesheet href="/assets/style.css"></head>';
    if (!$u) { echo '<body class=auth><div class="blob b1"></div><div class="blob b2"></div><div class=authbox>' . ($m ? '<div class="alert err">' . e($m) . '</div>' : '') . $b . '</div><script src="/assets/app.js"></script></body></html>'; exit; }
    $all = ['dash' => '🏠 Dashboard', 'classes' => '🎓 Classes', 'fees' => '💰 Fees', 'feestatus' => '🧾 Fee Status', 'attendance' => '✅ Attendance', 'marks' => '📝 Marks', 'notes' => '📚 Notes', 'events' => '📅 Events'];
    $by = ['admin' => array_keys($all), 'faculty' => ['dash', 'classes', 'attendance', 'marks', 'notes', 'events'], 'student' => ['dash', 'attendance', 'marks', 'fees', 'notes', 'events']];
    $p = $_GET['p'] ?? 'dash';
    echo '<body><nav class=side><div class=brand>Smart <span>SMS</span></div>';
    foreach ($by[$u['role']] as $k) echo '<a class="' . ($p === $k ? 'on' : '') . '" href="?p=' . $k . '">' . $all[$k] . '</a>';
    echo '<a class=out href="?p=logout">🚪 Logout</a></nav><main><header><div><h2>' . e($t) . '</h2><p>' . e($u['name']) . ' · <span class=pill>' . e($u['role']) . '</span></p></div><button id=th>🌓</button></header>'
        . ($m ? '<div class=alert>' . e($m) . '</div>' : '') . $b . '</main><script src="/assets/app.js"></script></body></html>';
    exit;
}

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !hash_equals(csrf(), $_POST['c'] ?? '')) { http_response_code(400); die('Bad request'); }
$p = $_GET['p'] ?? 'dash';
$cl = (string)($_REQUEST['cl'] ?? '5'); if (!in_array($cl, ['5', '6', '7', '8', '9', '10'], true)) $cl = '5';
$d = (string)($_REQUEST['d'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $d = date('Y-m-d');

switch ($p) {
case 'login':
    if ($post) {
        $r = rows('SELECT * FROM users WHERE email=?', [trim($_POST['email'] ?? '')]);
        if ($r && password_verify($_POST['pass'] ?? '', $r[0]['pass'])) {
            session_regenerate_id(true);
            $_SESSION['u'] = ['id' => (int)$r[0]['id'], 'name' => $r[0]['name'], 'role' => $r[0]['role'], 'cl' => $r[0]['class_name']];
            go('dash');
        }
        go('login', 'Invalid email or password');
    }
    view('Login', '<div class="brand big">Smart <span>SMS</span></div><h2>Login</h2><form method=post>' . f() . '<input name=email type=email placeholder=Email required><input name=pass type=password placeholder=Password required><button class=btn>Login</button></form><p><a href="?p=register">Create student account</a></p>');
case 'register':
    if ($post) {
        $n = trim($_POST['name'] ?? ''); $em = trim($_POST['email'] ?? ''); $pw = $_POST['pass'] ?? ''; $rn = trim($_POST['roll'] ?? '');
        if (!$n || !filter_var($em, FILTER_VALIDATE_EMAIL) || strlen($pw) < 6 || !$rn) go('register', 'Fill all fields (password min 6 chars)');
        try { q("INSERT INTO users(name,email,pass,roll_no,role,class_name) VALUES(?,?,?,?, 'student',?)", [$n, $em, password_hash($pw, PASSWORD_DEFAULT), $rn, $cl]); }
        catch (Exception $x) { go('register', 'Email or roll number already exists'); }
        go('login', 'Account created. Please login.');
    }
    view('Register', '<h2>Student Registration</h2><form method=post>' . f() . '<input name=name placeholder="Full name" required><input name=email type=email placeholder=Email required><input name=pass type=password placeholder="Password (min 6)" required><input name=roll placeholder="Roll no" required>' . clsel($cl, 'cl', false) . '<button class=btn>Register</button></form><p><a href="?p=login">Back to login</a></p>');
case 'logout':
    $_SESSION = []; session_destroy(); header('Location: ?p=login'); exit;

case 'dash':
    $u = need(); $o = '<div class=grid>';
    if ($u['role'] === 'student') {
        $a = rows("SELECT COUNT(*) t, SUM(status='P') p FROM attendance WHERE student_id=?", [$u['id']])[0];
        $due = one('SELECT COALESCE(SUM(amount),0) FROM fees WHERE student_id=? AND paid=0', [$u['id']]);
        $avg = one('SELECT AVG(score/max_score*100) FROM marks WHERE student_id=?', [$u['id']]);
        $o .= stat('✅', $a['t'] ? round($a['p'] / $a['t'] * 100) . '%' : '-', 'Attendance') . stat('💰', $due, 'Fees due') . stat('📝', $avg !== null ? round($avg) . '%' : '-', 'Average marks') . stat('🎓', 'Class ' . $u['cl'], 'Your class');
    } else {
        $o .= stat('🎓', one("SELECT COUNT(*) FROM users WHERE role='student'"), 'Students') . stat('👩‍🏫', one("SELECT COUNT(*) FROM users WHERE role='faculty'"), 'Faculty') . stat('📅', one('SELECT COUNT(*) FROM events WHERE date>=CURDATE()'), 'Upcoming events') . stat('📚', one('SELECT COUNT(*) FROM notes'), 'Notes');
    }
    view('Dashboard', $o . '</div>');

case 'classes':
    $u = need('admin', 'faculty'); $o = '';
    if ($post && $u['role'] === 'admin' && ($_POST['do'] ?? '') === 'faculty') {
        $pw = $_POST['pass'] ?? '';
        if (strlen($pw) < 6 || !filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL)) go('classes', 'Invalid faculty details');
        try { q("INSERT INTO users(name,email,pass,role) VALUES(?,?,?, 'faculty')", [trim($_POST['name']), trim($_POST['email']), password_hash($pw, PASSWORD_DEFAULT)]); }
        catch (Exception $x) { go('classes', 'Email already exists'); }
        go('classes', 'Faculty added');
    }
    $o .= pick('classes', $cl);
    $o .= tbl(['Roll', 'Name'], array_map(fn($s) => [e($s['roll_no']), e($s['name'])], students($cl)));
    if ($u['role'] === 'admin') $o .= '<div class=card><h3>Add faculty</h3><form method=post>' . f() . '<input type=hidden name=do value=faculty><div class=row><input name=name placeholder=Name required><input name=email type=email placeholder=Email required><input name=pass type=password placeholder="Password (min 6)" required></div><button class=btn>Add</button></form></div>';
    view('Class ' . $cl . ' students', $o);

case 'attendance':
    $u = need();
    if ($u['role'] === 'student') {
        $r = rows('SELECT date,status FROM attendance WHERE student_id=? ORDER BY date DESC', [$u['id']]);
        view('My Attendance', tbl(['Date', 'Status'], array_map(fn($x) => [e($x['date']), '<span class="tag ' . ($x['status'] === 'P' ? 'g' : 'r') . '">' . ($x['status'] === 'P' ? 'Present' : 'Absent') . '</span>'], $r)));
    }
    need('admin', 'faculty');
    if ($post) {
        foreach ($_POST['s'] ?? [] as $id => $s) if (in_array($s, ['P', 'A'], true)) q('INSERT INTO attendance(student_id,date,status) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)', [(int)$id, $d, $s]);
        go("attendance&cl=$cl&d=$d", 'Attendance saved');
    }
    $ex = array_column(rows('SELECT student_id,status FROM attendance WHERE date=?', [$d]), 'status', 'student_id');
    $r = [];
    foreach (students($cl) as $s) { $v = $ex[$s['id']] ?? 'P'; $r[] = [e($s['roll_no']), e($s['name']), '<label><input type=radio name="s[' . $s['id'] . ']" value=P ' . ($v === 'P' ? 'checked' : '') . '> P</label> <label><input type=radio name="s[' . $s['id'] . ']" value=A ' . ($v === 'A' ? 'checked' : '') . '> A</label>']; }
    view('Attendance', pick('attendance', $cl, '<input type=date name=d value="' . e($d) . '" onchange="this.form.submit()">') . '<form method=post>' . f() . '<input type=hidden name=cl value=' . $cl . '><input type=hidden name=d value="' . e($d) . '">' . tbl(['Roll', 'Name', 'Status'], $r) . ($r ? '<button class=btn>Save</button>' : '') . '</form>');

case 'marks':
    $u = need();
    if ($u['role'] === 'student') {
        $r = rows('SELECT subject,score,max_score FROM marks WHERE student_id=? ORDER BY subject', [$u['id']]);
        view('My Marks', tbl(['Subject', 'Score', 'Progress'], array_map(fn($x) => [e($x['subject']), e($x['score'] + 0) . ' / ' . e($x['max_score'] + 0), '<div class=bar><i style="width:' . min(100, round($x['score'] / max(1, $x['max_score']) * 100)) . '%"></i></div>'], $r)));
    }
    need('admin', 'faculty');
    if ($post) {
        $sub = trim($_POST['subject'] ?? ''); $mx = (float)($_POST['max'] ?? 100) ?: 100;
        if (!$sub) go("marks&cl=$cl", 'Enter a subject');
        foreach ($_POST['m'] ?? [] as $id => $sc) if ($sc !== '' && is_numeric($sc)) {
            q('DELETE FROM marks WHERE student_id=? AND subject=?', [(int)$id, $sub]);
            q('INSERT INTO marks(student_id,subject,score,max_score) VALUES(?,?,?,?)', [(int)$id, $sub, (float)$sc, $mx]);
        }
        go("marks&cl=$cl", 'Marks saved');
    }
    $r = array_map(fn($s) => [e($s['roll_no']), e($s['name']), '<input name="m[' . $s['id'] . ']" type=number step=0.01 min=0 placeholder=Score>'], students($cl));
    view('Marks', pick('marks', $cl) . '<form method=post>' . f() . '<input type=hidden name=cl value=' . $cl . '><div class="card row"><input name=subject placeholder=Subject required><input name=max type=number value=100 placeholder="Max score"></div>' . tbl(['Roll', 'Name', 'Score'], $r) . ($r ? '<button class=btn>Save marks</button>' : '') . '</form>');

case 'fees':
    $u = need();
    if ($u['role'] === 'student') {
        $r = rows('SELECT title,amount,paid,due_date FROM fees WHERE student_id=? ORDER BY due_date', [$u['id']]);
        view('My Fees', tbl(['Title', 'Amount', 'Due date', 'Status'], array_map(fn($x) => [e($x['title']), e($x['amount']), e($x['due_date']), '<span class="tag ' . ($x['paid'] ? 'g' : 'r') . '">' . ($x['paid'] ? 'Paid' : 'Not Paid') . '</span>'], $r)));
    }
    need('admin');
    if ($post) {
        $t = trim($_POST['title'] ?? ''); $amt = (float)($_POST['amount'] ?? 0); $due = $_POST['due'] ?? '';
        if (!$t || $amt <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) go('fees', 'Enter title, amount and due date');
        foreach (students($cl) as $s) q('INSERT INTO fees(student_id,title,amount,paid,due_date) VALUES(?,?,?,0,?)', [$s['id'], $t, $amt, $due]);
        go('fees', 'Fee set for class ' . $cl);
    }
    $sum = rows('SELECT u.class_name c, SUM(f.amount) t, SUM(f.amount*f.paid) p FROM fees f JOIN users u ON u.id=f.student_id GROUP BY u.class_name ORDER BY u.class_name+0');
    view('Fees', '<div class=card><h3>Set fee for a whole class</h3><form method=post>' . f() . '<div class=row>' . clsel($cl, 'cl', false) . '<input name=title placeholder="Fee title" required><input name=amount type=number step=0.01 placeholder=Amount required><input name=due type=date required></div><button class=btn>Apply to class</button></form></div>'
        . tbl(['Class', 'Total', 'Collected', 'Pending'], array_map(fn($x) => [e($x['c']), e($x['t'] + 0), e($x['p'] + 0), e($x['t'] - $x['p'])], $sum)));

case 'feestatus':
    need('admin');
    if ($post && isset($_POST['pay'])) { q('UPDATE fees SET paid=1 WHERE student_id=?', [(int)$_POST['pay']]); go("feestatus&cl=$cl", 'Marked as paid'); }
    $r = [];
    foreach (rows("SELECT u.id,u.name,u.roll_no,COALESCE(SUM(f.amount),0) t,COALESCE(SUM(f.amount*f.paid),0) pd FROM users u LEFT JOIN fees f ON f.student_id=u.id WHERE u.role='student' AND u.class_name=? GROUP BY u.id,u.name,u.roll_no ORDER BY u.roll_no", [$cl]) as $s) {
        $st = $s['t'] == 0 ? '<span class=muted>No fee</span>' : ($s['pd'] >= $s['t'] ? '<span class="tag g">Paid</span>' : ($s['pd'] == 0 ? '<span class="tag r">Not Paid</span>' : '<span class="tag r">Due ' . e($s['t'] - $s['pd']) . '</span>'));
        $act = ($s['t'] > $s['pd']) ? '<form method=post>' . f() . '<input type=hidden name=cl value=' . $cl . '><button class="mini" name=pay value=' . $s['id'] . '>Mark paid</button></form>' : '';
        $r[] = [e($s['roll_no']), e($s['name']), e($s['t'] + 0), e($s['pd'] + 0), $st, $act];
    }
    view('Fee Status', pick('feestatus', $cl) . tbl(['Roll', 'Name', 'Total', 'Paid', 'Status', ''], $r));

case 'notes':
    $u = need();
    if ($post && $u['role'] !== 'student') {
        $c = ($_POST['cl'] ?? '') === 'all' ? null : $cl;
        q('INSERT INTO notes(title,body,author,class_name) VALUES(?,?,?,?)', [trim($_POST['title'] ?? ''), trim($_POST['body'] ?? ''), $u['name'], $c]);
        go('notes', 'Note posted');
    }
    $n = $u['role'] === 'student' ? rows('SELECT * FROM notes WHERE class_name=? OR class_name IS NULL ORDER BY id DESC', [$u['cl']]) : rows('SELECT * FROM notes ORDER BY id DESC');
    $o = '';
    if ($u['role'] !== 'student') $o .= '<div class=card><h3>Post a note</h3><form method=post>' . f() . '<div class=row><select name=cl><option value=all>All classes</option>' . implode('', array_map(fn($c) => '<option>' . $c . '</option>', [5, 6, 7, 8, 9, 10])) . '</select><input name=title placeholder=Title required></div><textarea name=body placeholder=Note required></textarea><button class=btn>Post</button></form></div>';
    foreach ($n as $x) $o .= '<div class=card><h3>' . e($x['title']) . ' <span class=pill>' . ($x['class_name'] ? 'Class ' . e($x['class_name']) : 'All') . '</span></h3><p>' . nl2br(e($x['body'])) . '</p><small class=muted>' . e($x['author']) . ' · ' . e($x['created_at']) . '</small></div>';
    view('Notes', $o);

case 'events':
    $u = need();
    if ($post && $u['role'] === 'admin') {
        if (trim($_POST['title'] ?? '') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '')) q('INSERT INTO events(title,date,info) VALUES(?,?,?)', [trim($_POST['title']), $_POST['date'], trim($_POST['info'] ?? '')]);
        go('events', 'Event added');
    }
    $o = $u['role'] === 'admin' ? '<div class=card><h3>Add event</h3><form method=post>' . f() . '<div class=row><input name=title placeholder=Title required><input name=date type=date required><input name=info placeholder=Info></div><button class=btn>Add</button></form></div>' : '';
    view('Events', $o . tbl(['Date', 'Event', 'Info'], array_map(fn($x) => [e($x['date']), e($x['title']), e($x['info'])], rows('SELECT * FROM events ORDER BY date DESC'))));

default:
    go(me() ? 'dash' : 'login');
}

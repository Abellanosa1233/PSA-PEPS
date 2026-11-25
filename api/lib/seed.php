<?php
declare(strict_types=1);

/* Creates tables (if needed) and loads realistic demo data relative to today.
   Users are never touched, so the admin login survives a demo reset. */

function install_schema(): void
{
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        db()->exec($stmt);
    }
}

function seed_demo(): void
{
    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['payslips', 'pay_bonuses', 'pay_runs', 'candidates', 'positions', 'events', 'attendance', 'employee_requests', 'leave_requests', 'employees', 'settings'] as $t) {
        $pdo->exec("TRUNCATE TABLE $t");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    mt_srand(20261123);
    $pick = function (array $a) { return $a[mt_rand(0, count($a) - 1)]; };
    $rnd = function () { return mt_rand() / mt_getrandmax(); };
    $T = new DateTimeImmutable('today');
    $d = function (int $offset) use ($T) { return $T->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d'); };

    $titles = [
        'Design' => ['Product designer', 'UX researcher', 'Brand designer'],
        'Engineering' => ['Software engineer', 'Senior software engineer', 'Frontend engineer', 'Backend engineer', 'QA engineer'],
        'People' => ['HR generalist', 'Recruiter'],
        'Product' => ['Product manager', 'Product analyst'],
        'Marketing' => ['Content strategist', 'Growth marketer'],
        'Sales' => ['Account executive', 'Sales development rep'],
        'Finance' => ['Accountant', 'Payroll specialist'],
        'Operations' => ['Customer success lead', 'Office coordinator', 'Support specialist'],
    ];
    // Annual base salaries in PHP
    $base = ['Design' => 660000, 'Engineering' => 900000, 'People' => 480000, 'Product' => 1020000, 'Marketing' => 540000, 'Sales' => 480000, 'Finance' => 600000, 'Operations' => 420000];
    $cities = ['Makati City', 'Taguig City', 'Quezon City', 'Pasig City', 'Cebu City', 'Davao City', 'Remote'];
    $heads = [
        ['Alex', 'Morgan', 'People', 'HR administrator'], ['Jamie', 'Chen', 'Design', 'Design lead'],
        ['Demi', 'Wilkinson', 'Engineering', 'Engineering manager'], ['Candice', 'Wu', 'Product', 'Head of product'],
        ['Natali', 'Craig', 'Marketing', 'Marketing manager'], ['Drew', 'Cano', 'Sales', 'Sales manager'],
        ['Orlando', 'Diggs', 'Finance', 'Finance manager'], ['Andi', 'Lane', 'Operations', 'Operations manager'],
    ];
    $named = [['Olivia', 'Rhye', 'Design', 'Product designer'], ['Phoenix', 'Baker', 'Engineering', 'Software engineer'], ['Lana', 'Steiner', 'People', 'People operations']];
    $firsts = ['Kate', 'Koray', 'Ava', 'Noah', 'Mia', 'Liam', 'Zara', 'Ethan', 'Priya', 'Mateo', 'Chloe', 'Lucas', 'Amara', 'Hugo', 'Sofia', 'Ravi', 'Isla', 'Theo', 'Nadia', 'Kai', 'Leah', 'Omar', 'Ruby', 'Felix', 'Yuki', 'Jonah', 'Elena', 'Sami', 'Grace', 'Arjun', 'Maya', 'Dylan'];
    $lasts = ['Morrow', 'Okonkwo', 'Bennett', 'Fischer', 'Patel', 'Alvarez', 'Kim', 'Novak', 'Hughes', 'Silva', 'Moreau', 'Larsen', 'Singh', 'Reyes', 'Walsh', 'Tanaka', 'Brooks', 'Haddad', 'Quinn', 'Ferreira', 'Lund', 'Osei', 'Park', 'Romero', 'Ward', 'Ibrahim', 'Cole', 'Nguyen', 'Ellis', 'Rossi', 'Shah', 'Byrne'];

    $insEmp = $pdo->prepare('INSERT INTO employees (first_name,last_name,email,phone,title,department,location,emp_type,start_date,salary,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $emps = [];
    $mk = function (string $f, string $l, string $dept, string $title, string $start, bool $head = false) use ($insEmp, $pdo, $pick, $rnd, $base, $cities, &$emps) {
        $type = $head ? 'Full-time' : ($rnd() < 0.88 ? 'Full-time' : ($rnd() < 0.5 ? 'Part-time' : 'Contract'));
        $salary = round($base[$dept] * (0.85 + $rnd() * 0.45) * ($head ? 1.3 : 1) / 1000) * 1000;
        $mobile = '+63 9' . $pick(['17', '18', '19', '20', '27', '45', '55', '66', '77', '95', '98']) . ' ' . mt_rand(100, 999) . ' ' . mt_rand(1000, 9999);
        $insEmp->execute([$f, $l, strtolower("$f.$l") . '@horizon.studio', $mobile,
            $title, $dept, $pick($cities), $type, $start, $salary, 'Active']);
        $id = (int) $pdo->lastInsertId();
        $emps[$id] = ['id' => $id, 'dept' => $dept, 'start' => $start, 'name' => "$f $l"];
        return $id;
    };
    $headOf = [];
    foreach ($heads as $h) $headOf[$h[2]] = $mk($h[0], $h[1], $h[2], $h[3], $d(-mt_rand(900, 2100)), true);
    $namedIds = [];
    foreach ($named as $h) $namedIds[] = $mk($h[0], $h[1], $h[2], $h[3], $d(-mt_rand(200, 1100)));
    foreach ($firsts as $i => $f) {
        $dept = DEPARTMENTS[$i % count(DEPARTMENTS)];
        $recent = $i >= count($firsts) - 4;
        $mk($f, $lasts[$i], $dept, $pick($titles[$dept]), $d($recent ? -mt_rand(3, 27) : -mt_rand(60, 1360)));
    }
    $setMgr = $pdo->prepare('UPDATE employees SET manager_id = ? WHERE id = ?');
    foreach ($emps as $e) {
        if ($headOf[$e['dept']] !== $e['id']) $setMgr->execute([$headOf[$e['dept']], $e['id']]);
    }
    $ids = array_keys($emps);
    $leaverId = $ids[count($ids) - 6];
    q("UPDATE employees SET status = 'Offboarded', end_date = ? WHERE id = ?", [$d(-20), $leaverId]);
    unset($emps[$leaverId]);

    /* Time off */
    $leaves = [];
    $insLeave = $pdo->prepare('INSERT INTO leave_requests (emp_id,leave_type,start_date,end_date,days,status,reason,created_at,decided_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $req = function (int $emp, string $type, int $s, int $e, string $status, string $reason, int $ago) use ($insLeave, $d, &$leaves) {
        $start = $d($s); $end = $d($e);
        $insLeave->execute([$emp, $type, $start, $end, workdays($start, $end), $status, $reason, $d(-$ago), $status === 'pending' ? null : $d(-$ago) . ' 10:00:00']);
        if ($status === 'approved') $leaves[] = [$emp, $start, $end];
    };
    $req($namedIds[0], 'Annual leave', 3, 4, 'pending', 'Family visit', 2);
    $req($namedIds[1], 'Personal leave', 2, 2, 'pending', 'Moving apartments', 1);
    $req($namedIds[2], 'Annual leave', 7, 9, 'pending', 'Short trip', 1);
    $veterans = array_values(array_filter($emps, function ($e) use ($d) { return $e['start'] < $d(-40); }));
    foreach ([12, 15, 19, 22] as $i => $k) $req($veterans[$k]['id'], 'Annual leave', -1 - $i % 2, 1 + $i % 3, 'approved', 'Holiday', 14);
    $req($veterans[25]['id'], 'Sick leave', 0, 0, 'approved', 'Flu', 0);
    for ($k = 0; $k < 12; $k++) {
        $s = -mt_rand(10, 160);
        $req($veterans[($k * 7 + 3) % count($veterans)]['id'], $pick(['Annual leave', 'Annual leave', 'Sick leave', 'Personal leave']), $s, $s + mt_rand(0, 3), 'approved', '', 30);
    }
    $req($veterans[8]['id'], 'Annual leave', 18, 22, 'approved', 'Year-end break', 6);
    $req($veterans[30 % count($veterans)]['id'], 'Unpaid leave', -40, -36, 'rejected', 'Extended travel', 50);

    $onLeave = function (int $emp, string $day) use (&$leaves) {
        foreach ($leaves as $l) if ($l[0] === $emp && $l[1] <= $day && $l[2] >= $day) return true;
        return false;
    };

    /* Attendance for the past 7 weeks */
    $insAtt = $pdo->prepare('INSERT INTO attendance (work_date,emp_id,status,time_in,time_out) VALUES (?,?,?,?,?)');
    $pdo->beginTransaction();
    for ($back = 49; $back >= 0; $back--) {
        $day = $T->modify("-$back days");
        if ((int) $day->format('N') >= 6) continue;
        $di = $day->format('Y-m-d');
        foreach ($emps as $e) {
            if ($e['start'] > $di || $onLeave($e['id'], $di)) continue;
            $x = $rnd();
            $st = $x < 0.71 ? 'office' : ($x < 0.94 ? 'remote' : 'away');
            if ($st === 'away') { $insAtt->execute([$di, $e['id'], 'away', null, null]); continue; }
            $in = 8 * 60 + 25 + mt_rand(0, 64);
            $o = $in + 480 + mt_rand(0, 70);
            $out = $back === 0 ? null : sprintf('%02d:%02d', intdiv($o, 60), $o % 60);
            $insAtt->execute([$di, $e['id'], $st, sprintf('%02d:%02d', intdiv($in, 60), $in % 60), $out]);
        }
    }
    $pdo->commit();

    /* Schedule */
    $insEv = $pdo->prepare('INSERT INTO events (event_date,start_time,end_time,title,detail) VALUES (?,?,?,?,?)');
    foreach ([
        [0, '09:30', '10:00', 'People team sync', 'Weekly check-in · 6 people'],
        [0, '11:00', '11:45', 'Product designer interview', 'With Jamie Chen · Interview'],
        [0, '14:30', '15:00', 'New hire orientation', '2 new teammates · Onboarding'],
        [1, '10:00', '11:00', 'Payroll review', 'Finance · Monthly close'],
        [2, '13:00', '14:00', 'Quarterly all-hands', 'Whole company · Main hall'],
    ] as $ev) $insEv->execute([$d($ev[0]), $ev[1], $ev[2], $ev[3], $ev[4]]);

    /* Recruitment */
    $candFirst = ['Ines', 'Marcus', 'Hana', 'Tobias', 'Aisha', 'Pablo', 'Freya', 'Kenji', 'Lena', 'Victor', 'Ada', 'Bruno', 'Selin', 'Owen', 'Talia', 'Diego', 'Nora', 'Emil', 'Rosa', 'Idris', 'Clara', 'Malik', 'Signe', 'Julian'];
    $candLast = ['Carvalho', 'Hale', 'Sato', 'Krause', 'Bello', 'Ortega', 'Lindqvist', 'Mori', 'Weber', 'Laurent', 'Obi', 'Costa', 'Yilmaz', 'Grant', 'Levi', 'Mendes', 'Berg', 'Holm', 'Vidal', 'Rahman', 'Duval', 'Adeyemi', 'Nilsen', 'Frost'];
    $insPos = $pdo->prepare('INSERT INTO positions (title,department,emp_type,location,status,created_at) VALUES (?,?,?,?,?,?)');
    $insCand = $pdo->prepare('INSERT INTO candidates (position_id,name,email,source,stage,applied_at) VALUES (?,?,?,?,?,?)');
    $ci = 0;
    foreach ([['Senior product designer', 'Design', 10], ['Frontend engineer', 'Engineering', 6], ['People operations specialist', 'People', 8],
              ['Backend engineer', 'Engineering', 0], ['Account executive', 'Sales', 0], ['Content strategist', 'Marketing', 0],
              ['Financial analyst', 'Finance', 0], ['Customer success lead', 'Operations', 0]] as $p) {
        $insPos->execute([$p[0], $p[1], 'Full-time', $pick(['Makati City', 'Taguig City', 'Cebu City', 'Remote']), 'open', $d(-mt_rand(5, 45))]);
        $pid = (int) $pdo->lastInsertId();
        for ($c = 0; $c < $p[2]; $c++, $ci++) {
            $fn = $candFirst[$ci % 24]; $ln = $candLast[($ci * 5) % 24];
            $roll = $rnd();
            $stage = $roll < 0.35 ? 'Applied' : ($roll < 0.65 ? 'Screening' : ($roll < 0.9 ? 'Interview' : 'Offer'));
            $insCand->execute([$pid, "$fn $ln", strtolower("$fn.$ln") . '@mail.com', $pick(['LinkedIn', 'Referral', 'Careers page', 'Agency']), $stage, $d(-mt_rand(1, 30))]);
        }
    }

    /* Payroll: three paid months plus the current month as a draft */
    $first = $T->modify('first day of this month');
    $insBonus = $pdo->prepare('INSERT INTO pay_bonuses (run_id, emp_id, amount) VALUES (?,?,?)');
    for ($m = 3; $m >= 0; $m--) {
        $period = $first->modify("-$m months")->format('Y-m');
        q('INSERT INTO pay_runs (period, status) VALUES (?, ?)', [$period, 'draft']);
        $runId = (int) $pdo->lastInsertId();
        foreach ($ids as $eid) if ($rnd() < 0.12) $insBonus->execute([$runId, $eid, mt_rand(4, 30) * 500]);
        if ($m > 0) pay_run($runId, period_end($period) . ' 17:00:00');
    }

    set_setting('selfEmpId', $namedIds[0]);
    set_setting('supervisorId', $headOf['Design']);
}

function pay_run(int $runId, ?string $paidAt = null): void
{
    $run = q('SELECT * FROM pay_runs WHERE id = ?', [$runId])->fetch();
    if (!$run) fail('That pay run no longer exists.', 404);
    if ($run['status'] === 'paid') fail('This pay run has already been paid.');
    $lines = compute_lines($runId, $run['period']);
    if (!$lines) fail('There is nobody to pay in this period.');
    $ins = db()->prepare('INSERT INTO payslips (run_id,emp_id,emp_name,department,base,bonus,gross,tax,benefits,net) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $own = !db()->inTransaction();
    if ($own) db()->beginTransaction();
    foreach ($lines as $l) $ins->execute([$runId, $l['empId'], $l['name'], $l['department'], $l['base'], $l['bonus'], $l['gross'], $l['tax'], $l['benefits'], $l['net']]);
    q("UPDATE pay_runs SET status = 'paid', paid_at = ? WHERE id = ?", [$paidAt ?? date('Y-m-d H:i:s'), $runId]);
    if ($own) db()->commit();
}

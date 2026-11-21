<?php
include 'db.php';

// JSON API used by dashboard.js. All sorting data is stored in MySQL.
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    function api_json($data, $status = 200) {
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
    function current_batch($conn) {
        $result = $conn->query("SELECT * FROM sorting_batches ORDER BY batch_id DESC LIMIT 1");
        return $result ? $result->fetch_assoc() : null;
    }
    function batch_payload($conn, $batch) {
        if (!$batch) return ['batch' => null, 'records' => []];
        $stmt = $conn->prepare("SELECT egg_id, egg_weight, egg_size, quality_status, sorting_direction, sorting_status, inspected_at FROM egg_records WHERE batch_id=? ORDER BY egg_id DESC LIMIT 200");
        $stmt->bind_param('i', $batch['batch_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        $records = [];
        while ($r = $res->fetch_assoc()) {
            $records[] = [
                'id' => 'EGG-' . str_pad($r['egg_id'], 6, '0', STR_PAD_LEFT),
                'weight' => (float)$r['egg_weight'],
                'category' => $r['egg_size'] === 'Rejected' ? 'Rejected' : strtolower($r['egg_size']),
                'qualityStatus' => $r['quality_status'],
                'direction' => $r['sorting_direction'],
                'time' => date('h:i:s A', strtotime($r['inspected_at']))
            ];
        }
        $stmt->close();
        return ['batch' => $batch, 'records' => $records];
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? $_GET['api'];

    if ($method === 'GET' || $action === 'load') {
        $batch = current_batch($conn);
        $settings = $conn->query("SELECT batch_limit FROM machine_settings ORDER BY setting_id DESC LIMIT 1")->fetch_assoc();
        $payload = batch_payload($conn, $batch);
        $payload['batch_limit'] = $settings ? (int)$settings['batch_limit'] : 30;
        api_json($payload);
    }

    if ($action === 'set_limit') {
        $limit = filter_var($input['batch_limit'] ?? null, FILTER_VALIDATE_INT);
        if (!$limit || $limit < 1 || $limit > 10000) api_json(['error'=>'Batch limit must be 1 to 10,000.'], 422);
        $stmt = $conn->prepare("INSERT INTO machine_settings (batch_limit, conveyor_status, machine_status) VALUES (?, 'OFF', 'Ready')");
        $stmt->bind_param('i', $limit); $stmt->execute(); $stmt->close();
        $batch = current_batch($conn);
        if ($batch && $batch['batch_status'] !== 'Running') {
            $stmt = $conn->prepare("UPDATE sorting_batches SET batch_limit=? WHERE batch_id=?");
            $stmt->bind_param('ii', $limit, $batch['batch_id']); $stmt->execute(); $stmt->close();
        }
        api_json(['success'=>true, 'batch_limit'=>$limit]);
    }

    if ($action === 'start') {
        $settings = $conn->query("SELECT batch_limit FROM machine_settings ORDER BY setting_id DESC LIMIT 1")->fetch_assoc();
        $limit = $settings ? (int)$settings['batch_limit'] : 30;
        $batch = current_batch($conn);
        if (!$batch || in_array($batch['batch_status'], ['Completed','Stopped']) || (int)$batch['total_eggs'] >= (int)$batch['batch_limit']) {
            $stmt = $conn->prepare("INSERT INTO sorting_batches (batch_name, batch_limit, batch_status, started_at) VALUES (?, ?, 'Running', NOW())");
            $name = 'Batch ' . date('Ymd-His');
            $stmt->bind_param('si', $name, $limit); $stmt->execute();
            $batchId = $conn->insert_id; $stmt->close();
        } else {
            $batchId = (int)$batch['batch_id'];
            $stmt = $conn->prepare("UPDATE sorting_batches SET batch_status='Running', started_at=COALESCE(started_at,NOW()) WHERE batch_id=?");
            $stmt->bind_param('i', $batchId); $stmt->execute(); $stmt->close();
        }
        $conn->query("UPDATE machine_settings SET conveyor_status='ON', machine_status='Running' ORDER BY setting_id DESC LIMIT 1");
        $stmt = $conn->prepare("INSERT INTO workflow_logs (batch_id, workflow_step, log_status) VALUES (?, 'Power On & System Initialization', 'Started')");
        $stmt->bind_param('i', $batchId); $stmt->execute(); $stmt->close();
        api_json(['success'=>true, 'batch_id'=>$batchId, 'batch_limit'=>$limit]);
    }

    if ($action === 'add_egg') {
        $batch = current_batch($conn);
        if (!$batch || $batch['batch_status'] !== 'Running') api_json(['error'=>'No active sorting batch.'], 409);
        if ((int)$batch['total_eggs'] >= (int)$batch['batch_limit']) api_json(['error'=>'Batch limit reached.'], 409);
        $weight = (float)($input['weight'] ?? 0);
        $quality = $input['quality_status'] ?? 'Accepted';
        if ($weight <= 0 || $weight > 200) api_json(['error'=>'Invalid egg weight.'], 422);
        $allowed = ['Accepted','Cracked - Rejected','Suspected rotten - Rejected'];
        if (!in_array($quality, $allowed, true)) api_json(['error'=>'Invalid quality status.'], 422);
        if ($quality !== 'Accepted') { $size='Rejected'; $direction='Reject tray'; $sortStatus='Rejected'; }
        else {
            if ($weight <= 49) $size='Small'; elseif ($weight <= 59) $size='Medium'; elseif ($weight <= 69) $size='Large'; else $size='XL';
            $direction = $size . ' tray'; $sortStatus='Sorted';
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO egg_records (batch_id, egg_weight, egg_size, quality_status, sorting_direction, sorting_status) VALUES (?, ?, ?, ?, ?, ?)");
            $bid=(int)$batch['batch_id'];
            $stmt->bind_param('idssss', $bid, $weight, $size, $quality, $direction, $sortStatus);
            $stmt->execute(); $eggId=$conn->insert_id; $stmt->close();
            $column = ['Small'=>'small_eggs','Medium'=>'medium_eggs','Large'=>'large_eggs','XL'=>'xl_eggs','Rejected'=>'rejected_eggs'][$size];
            $sql = "UPDATE sorting_batches SET total_eggs=total_eggs+1, {$column}={$column}+1 WHERE batch_id=?";
            $stmt=$conn->prepare($sql); $stmt->bind_param('i',$bid); $stmt->execute(); $stmt->close();
            $updated = current_batch($conn);
            $complete = (int)$updated['total_eggs'] >= (int)$updated['batch_limit'];
            if ($complete) {
                $stmt=$conn->prepare("UPDATE sorting_batches SET batch_status='Completed', completed_at=NOW() WHERE batch_id=?");
                $stmt->bind_param('i',$bid); $stmt->execute(); $stmt->close();
                $conn->query("UPDATE machine_settings SET conveyor_status='OFF', machine_status='Stopped' ORDER BY setting_id DESC LIMIT 1");
            }
            $step='Egg classified: ' . $size . ' / ' . $quality;
            $stmt=$conn->prepare("INSERT INTO workflow_logs (batch_id, workflow_step, sensor_reading, log_status) VALUES (?, ?, ?, ?)");
            $reading=$weight . ' g'; $logStatus=$complete?'Batch completed':'Completed';
            $stmt->bind_param('isss',$bid,$step,$reading,$logStatus); $stmt->execute(); $stmt->close();
            $conn->commit();
            $updated=current_batch($conn);
            api_json(['success'=>true,'egg_id'=>'EGG-'.str_pad($eggId,6,'0',STR_PAD_LEFT),'category'=>$size==='Rejected'?'Rejected':strtolower($size),'qualityStatus'=>$quality,'direction'=>$direction,'batch'=>$updated,'batch_complete'=>$complete]);
        } catch (Throwable $e) { $conn->rollback(); api_json(['error'=>'Could not save egg record: '.$e->getMessage()],500); }
    }

    if ($action === 'stop') {
        $batch=current_batch($conn);
        if ($batch && $batch['batch_status']==='Running') {
            $stmt=$conn->prepare("UPDATE sorting_batches SET batch_status='Stopped' WHERE batch_id=?");
            $stmt->bind_param('i',$batch['batch_id']); $stmt->execute(); $stmt->close();
            $stmt=$conn->prepare("INSERT INTO workflow_logs (batch_id, workflow_step, log_status) VALUES (?, ?, 'Stopped')");
            $reason=substr($input['reason'] ?? 'Manually stopped',0,150); $stmt->bind_param('is',$batch['batch_id'],$reason); $stmt->execute(); $stmt->close();
        }
        $conn->query("UPDATE machine_settings SET conveyor_status='OFF', machine_status='Stopped' ORDER BY setting_id DESC LIMIT 1");
        api_json(['success'=>true]);
    }

    if ($action === 'reset') {
        $batch=current_batch($conn);
        if ($batch && $batch['batch_status']==='Running') api_json(['error'=>'Stop the machine before resetting.'],409);
        $conn->query("UPDATE machine_settings SET conveyor_status='OFF', machine_status='Ready' ORDER BY setting_id DESC LIMIT 1");
        $settings=$conn->query("SELECT batch_limit FROM machine_settings ORDER BY setting_id DESC LIMIT 1")->fetch_assoc();
        $newLimit=$settings ? (int)$settings['batch_limit'] : 30;
        $stmt=$conn->prepare("INSERT INTO sorting_batches (batch_name, batch_limit, batch_status) VALUES (?, ?, 'Pending')");
        $newName='Batch ' . date('Ymd-His'); $stmt->bind_param('si',$newName,$newLimit); $stmt->execute(); $stmt->close();
        api_json(['success'=>true]);
    }
    api_json(['error'=>'Unknown API action.'], 400);
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    // This dashboard's existing front-end login uses localStorage. Keep the PHP page accessible
    // for that existing login flow; secure the API with your real PHP session when PHP login is added.
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>
        Dashboard | YHAN & KIEL Fresh Farm Egg
    </title>

    
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <!-- Dashboard CSS -->
    <link rel="stylesheet" href="dashboard.css">

</head>

<body>


<aside class="sidebar" id="sidebar">

    <div class="brand">

        <div class="brand-icon">
            <i class="fa-solid fa-egg"></i>
        </div>

        <div>
            <h2>YHAN & KIEL</h2>
            <span>FRESH FARM EGG</span>
        </div>

    </div>


    <nav class="navigation">

        <a href="#dashboard"
           class="nav-link active"
           data-section="dashboard">

            <i class="fa-solid fa-chart-line"></i>

            <span>Dashboard</span>

        </a>


        <a href="#sorting"
           class="nav-link"
           data-section="sorting">

            <i class="fa-solid fa-egg"></i>

            <span>Live Sorting</span>

        </a>


        <a href="#reports"
           class="nav-link"
           data-section="reports">

            <i class="fa-solid fa-file-lines"></i>

            <span>Reports</span>

        </a>


        <a href="#settings"
           class="nav-link"
           data-section="settings">

            <i class="fa-solid fa-gear"></i>

            <span>Admin Settings</span>

        </a>

    </nav>

    <div class="system-status">

        <span class="status-dot"></span>

        <div>
            <strong>System Online</strong>
            <small>Sorting machine ready</small>
        </div>

    </div>


    <button class="logout-button" id="logoutBtn">

        <i class="fa-solid fa-right-from-bracket"></i>

        <span>Logout</span>

    </button>

</aside>



<main class="main-content">


    <header class="topbar">

        <button
            class="menu-button"
            id="menuButton">

            <i class="fa-solid fa-bars"></i>

        </button>


        <div class="page-title">

            <h1>Egg Sorting Dashboard</h1>

            <p>
                YHAN & KIEL Fresh Farm Egg
            </p>

        </div>

            <div class="profile-icon">

                <i class="fa-solid fa-user"></i>

            </div>


        </div>

    </header>


    <section
        id="dashboard"
        class="content-section active-section">


        <div class="welcome-card">

            <div>

                <span class="welcome-label">
                    WELCOME BACK
                </span>

                <h2>
                    YHAN & KIEL Fresh Farm Egg
                </h2>

                <p>
                    Monitor and manage the automated
                    egg size sorting system.
                </p>

            </div>


            <div class="welcome-egg">

                <i class="fa-solid fa-egg"></i>

            </div>

        </div>

        <div class="statistics-grid">


            <div class="stat-card total">

                <div class="stat-icon">

                    <i class="fa-solid fa-egg"></i>

                </div>

                <div class="stat-info">

                    <span>Total Eggs</span>

                    <h2 id="totalEggs">0</h2>

                    <small>
                        Processed today
                    </small>

                </div>

            </div>


            <div class="stat-card small">

                <div class="stat-icon">

                    <i class="fa-solid fa-circle"></i>

                </div>

                <div class="stat-info">

                    <span>Small Eggs</span>

                    <h2 id="smallEggs">0</h2>

                    <small>
                        40–49 grams
                    </small>

                </div>

            </div>



            <div class="stat-card medium">

                <div class="stat-icon">

                    <i class="fa-solid fa-circle"></i>

                </div>

                <div class="stat-info">

                    <span>Medium Eggs</span>

                    <h2 id="mediumEggs">0</h2>

                    <small>
                        50–59 grams
                    </small>

                </div>

            </div>


            <div class="stat-card large">

                <div class="stat-icon">

                    <i class="fa-solid fa-circle"></i>

                </div>

                <div class="stat-info">

                    <span>Large Eggs</span>

                    <h2 id="largeEggs">0</h2>

                    <small>
                        60–69 grams
                    </small>

                </div>

            </div>


            <div class="stat-card xl">

                <div class="stat-icon">

                    <i class="fa-solid fa-circle"></i>

                </div>

                <div class="stat-info">

                    <span>XL Eggs</span>

                    <h2 id="xlEggs">0</h2>

                    <small>
                        70+ grams
                    </small>

                </div>

            </div>

        </div>


        <div class="dashboard-grid">


            <div class="panel sorting-panel">

                <div class="panel-header">

                    <div>

                        <h3>
                            <i class="fa-solid fa-gears"></i>
                            Live Egg Sorting
                        </h3>

                        <p>
                            Automated sorting process
                        </p>

                    </div>

                    <span
                        class="live-status"
                        id="machineStatus">

                        <span></span>
                        LIVE

                    </span>

                </div>



                <div class="sorting-machine">

                    <div class="machine-top">

                        <span>
                            AUTOMATED EGG SORTER
                        </span>

                        <i class="fa-solid fa-gear"></i>

                    </div>


                    <div class="conveyor-area">

                        <div
                            class="egg egg-1"
                            id="movingEgg">

                            <i class="fa-solid fa-egg"></i>

                        </div>


                        <div class="conveyor-line">

                            <div class="belt"></div>

                        </div>


                        <div class="sorting-bins">

                            <div class="sorting-bin small-bin">

                                <i class="fa-solid fa-box"></i>

                                <span>SMALL</span>

                            </div>


                            <div class="sorting-bin medium-bin">

                                <i class="fa-solid fa-box"></i>

                                <span>MEDIUM</span>

                            </div>


                            <div class="sorting-bin large-bin">

                                <i class="fa-solid fa-box"></i>

                                <span>LARGE</span>

                            </div>


                            <div class="sorting-bin xl-bin">

                                <i class="fa-solid fa-box"></i>

                                <span>XL</span>

                            </div>

                        </div>

                    </div>

                </div>


                <div class="sorting-controls">

                    <button
                        class="control-btn start"
                        id="startSorting">

                        <i class="fa-solid fa-play"></i>

                        Start Sorting

                    </button>


                    <button
                        class="control-btn stop"
                        id="stopSorting">

                        <i class="fa-solid fa-stop"></i>

                        Stop

                    </button>


                    <button
                        class="control-btn reset"
                        id="resetSorting">

                        <i class="fa-solid fa-rotate"></i>

                        Reset

                    </button>

                </div>

                <div class="batch-limit-panel">
                    <label for="eggLimit">Batch egg limit</label>
                    <div class="batch-limit-controls">
                        <input type="number" id="eggLimit" min="1" max="10000" value="30">
                        <span>eggs</span>
                        <strong id="batchProgress">0 / 30</strong>
                    </div>
                    <small>The machine stops automatically when this number of eggs has been inspected.</small>
                </div>

            </div>


            <div class="panel progress-panel">

                <div class="panel-header">

                    <div>

                        <h3>
                            Sorting Summary
                        </h3>

                        <p>
                            Today's egg distribution
                        </p>

                    </div>

                </div>


                <div class="progress-item">

                    <div class="progress-label">

                        <span>
                            Small
                        </span>

                        <strong id="smallPercent">
                            total
                        </strong>

                    </div>

                    <div class="progress-bar">

                        <div
                            id="smallProgress"
                            class="progress-fill small-fill">
                        </div>

                    </div>

                </div>


                <div class="progress-item">

                    <div class="progress-label">

                        <span>
                            Medium
                        </span>

                        <strong id="mediumPercent">
                            total
                        </strong>

                    </div>

                    <div class="progress-bar">

                        <div
                            id="mediumProgress"
                            class="progress-fill medium-fill">
                        </div>

                    </div>

                </div>


                <div class="progress-item">

                    <div class="progress-label">

                        <span>
                            Large
                        </span>

                        <strong id="largePercent">
                            total
                        </strong>

                    </div>

                    <div class="progress-bar">

                        <div
                            id="largeProgress"
                            class="progress-fill large-fill">
                        </div>

                    </div>

                </div>


                <div class="progress-item">

                    <div class="progress-label">

                        <span>
                            XL
                        </span>

                        <strong id="xlPercent">
                            total
                        </strong>

                    </div>

                    <div class="progress-bar">

                        <div
                            id="xlProgress"
                            class="progress-fill xl-fill">
                        </div>

                    </div>

                </div>


                <div class="production-summary">

                    <div>

                        <span>
                            Sorting Status
                        </span>

                        <strong id="sortingStatus">
                            Ready
                        </strong>

                    </div>


                    <div>

                        <span>
                            Eggs/Minute
                        </span>

                        <strong id="eggsPerMinute">
                            0
                        </strong>

                    </div>

                    <div>
                        <span>Rejected eggs</span>
                        <strong id="rejectedEggs">0</strong>
                    </div>

                </div>

            </div>

        </div>


        <div class="panel activity-panel">

            <div class="panel-header">

                <div>

                    <h3>
                        <i class="fa-solid fa-clock-rotate-left"></i>
                        Recent Sorting Activity
                    </h3>

                    <p>
                        Latest eggs processed
                    </p>

                </div>

                <button
                    class="view-report"
                    data-section="reports">

                    View Reports

                </button>

            </div>


            <div class="table-container">

                <table>

                    <thead>

                        <tr>

                            <th>Time</th>
                            <th>Egg ID</th>
                            <th>Weight</th>
                            <th>Category</th>
                            <th>Status</th>

                        </tr>

                    </thead>


                    <tbody id="activityTable">

                        <tr>

                            <td>--:--</td>

                            <td>EGG-0000</td>

                            <td>-- g</td>

                            <td>Waiting</td>

                            <td>
                                <span class="status waiting">
                                    Waiting
                                </span>
                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>

        </div>

    </section>


    <section
        id="sorting"
        class="content-section">

        <div class="section-heading">

            <div>

                <h2>Live Egg Sorting</h2>

                <p>
                    Monitor the automated egg
                    size classification process.
                </p>

            </div>

            <span class="live-status">

                <span></span>
                SYSTEM LIVE

            </span>

        </div>


        <div class="large-sorting-machine">

            <div class="machine-header">

                <h2>
                    YHAN & KIEL
                    AUTOMATED EGG SIZE SORTER
                </h2>

                <i class="fa-solid fa-gear spinning"></i>

            </div>


            <div class="large-conveyor">

                <div
                    class="large-egg"
                    id="largeMovingEgg">

                    <i class="fa-solid fa-egg"></i>

                </div>

                <div class="conveyor-belt"></div>

            </div>


            <div class="large-bins">

                <div class="large-bin-card small-bin">

                    <i class="fa-solid fa-egg"></i>

                    <h3>SMALL</h3>

                    <strong id="liveSmall">
                        0
                    </strong>

                    <span>40–49 g</span>

                </div>


                <div class="large-bin-card medium-bin">

                    <i class="fa-solid fa-egg"></i>

                    <h3>MEDIUM</h3>

                    <strong id="liveMedium">
                        0
                    </strong>

                    <span>50–59 g</span>

                </div>


                <div class="large-bin-card large-bin">

                    <i class="fa-solid fa-egg"></i>

                    <h3>LARGE</h3>

                    <strong id="liveLarge">
                        0
                    </strong>

                    <span>60–69 g</span>

                </div>


                <div class="large-bin-card xl-bin">

                    <i class="fa-solid fa-egg"></i>

                    <h3>XL</h3>

                    <strong id="liveXL">
                        0
                    </strong>

                    <span>70+ g</span>

                </div>

                <div class="large-bin-card reject-bin-card">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <h3>REJECTED</h3>
                    <strong id="liveRejected">0</strong>
                    <span>Cracked / suspected rotten</span>
                </div>

            </div>

        </div>

        <div class="panel workflow-panel">
            <div class="panel-header">
                <div>
                    <h3><i class="fa-solid fa-diagram-project"></i> System Workflow</h3>
                    <p>Step-by-step egg inspection and sorting sequence</p>
                </div>
                <span class="workflow-indicator" id="workflowIndicator">READY</span>
            </div>
            <ol class="workflow-steps" id="workflowSteps">
                <li data-step="0" class="workflow-step"><span class="step-number">1</span><div><strong>Power On & Initialize</strong><small>System checks and prepares the machine</small></div></li>
                <li data-step="1" class="workflow-step"><span class="step-number">2</span><div><strong>Place Egg on Conveyor</strong><small>Egg is positioned for inspection</small></div></li>
                <li data-step="2" class="workflow-step"><span class="step-number">3</span><div><strong>Detect Egg Presence</strong><small>Infrared sensor detects the egg</small></div></li>
                <li data-step="3" class="workflow-step"><span class="step-number">4</span><div><strong>Move Conveyor</strong><small>12V DC gear motor moves the egg</small></div></li>
                <li data-step="4" class="workflow-step"><span class="step-number">5</span><div><strong>Measure Egg Weight</strong><small>Load cell and HX711 read the weight</small></div></li>
                <li data-step="5" class="workflow-step"><span class="step-number">6</span><div><strong>Check Egg Quality</strong><small>Inspect for cracks and suspected spoilage</small></div></li>
                <li data-step="6" class="workflow-step"><span class="step-number">7</span><div><strong>Compare Weight Standards</strong><small>Classify acceptable eggs by weight</small></div></li>
                <li data-step="7" class="workflow-step"><span class="step-number">8</span><div><strong>Activate Sorting Servo</strong><small>Servo guide selects the correct direction</small></div></li>
                <li data-step="8" class="workflow-step"><span class="step-number">9</span><div><strong>Direct Egg to Tray</strong><small>Small, Medium, Large, XL, or Reject bin</small></div></li>
                <li data-step="9" class="workflow-step"><span class="step-number">10</span><div><strong>Display Sorting Result</strong><small>Show category, quality, and batch count</small></div></li>
                <li data-step="10" class="workflow-step"><span class="step-number">11</span><div><strong>Ready for Next Egg</strong><small>Repeat until the batch limit is reached</small></div></li>
            </ol>
            <p class="workflow-note"><strong>Reject route:</strong> Cracked or suspected rotten eggs are directed to the separate REJECTED tray. When the batch limit is reached, the sorting cycle stops automatically.</p>
            <p class="workflow-hardware-note">Prototype note: this dashboard currently demonstrates the workflow using simulated readings. Real detection and physical movement require the Arduino, sensors, motor driver, and servo to be connected and programmed.</p>
        </div>

    </section>


    <section
        id="reports"
        class="content-section">

        <div class="section-heading">

            <div>

                <h2>Egg Sorting Reports</h2>

                <p>
                    View the sorting records generated
                    by the system.
                </p>

            </div>


            <button
                class="export-button"
                id="exportReport">

                <i class="fa-solid fa-download"></i>

                Export Report

            </button>

        </div>


        <div class="report-cards">

            <div class="report-card">

                <i class="fa-solid fa-egg"></i>

                <span>Total Processed</span>

                <strong id="reportTotal">
                    0
                </strong>

            </div>


            <div class="report-card">

                <i class="fa-solid fa-calendar-day"></i>

                <span>Today's Date</span>

                <strong id="reportDate">
                    --
                </strong>

            </div>


            <div class="report-card">

                <i class="fa-solid fa-gauge-high"></i>

                <span>System Status</span>

                <strong>
                    Online
                </strong>

            </div>

        </div>


        <div class="panel">

            <div class="panel-header">

                <h3>
                    Sorting Records
                </h3>

            </div>


            <div class="table-container">

                <table>

                    <thead>

                        <tr>

                            <th>No.</th>
                            <th>Egg ID</th>
                            <th>Weight</th>
                            <th>Category</th>
                            <th>Quality status</th>
                            <th>Time</th>

                        </tr>

                    </thead>

                    <tbody id="reportTable">

                    </tbody>

                </table>

            </div>

        </div>

    </section>


    <section
        id="settings"
        class="content-section">

        <div class="section-heading">

            <div>

                <h2>Admin Settings</h2>

                <p>
                    Manage system and administrator settings.
                </p>

            </div>

        </div>


        <div class="settings-grid">


            <div class="settings-card">

                <div class="settings-title">

                    <i class="fa-solid fa-user-gear"></i>

                    <div>

                        <h3>Administrator Profile</h3>

                        <p>
                            Manage your account information.
                        </p>

                    </div>

                </div>


                <label>
                    Username
                </label>

                <input
                    type="text"
                    value="admin"
                    id="adminUsername"
                >


                <label>
                    Administrator Name
                </label>

                <input
                    type="text"
                    value="Administrator"
                    id="adminName"
                >


                <button
                    class="save-button"
                    id="saveProfile">

                    <i class="fa-solid fa-floppy-disk"></i>

                    Save Profile

                </button>

            </div>


            <div class="settings-card">

                <div class="settings-title">

                    <i class="fa-solid fa-lock"></i>

                    <div>

                        <h3>Change Password</h3>

                        <p>
                            Update your administrator password.
                        </p>

                    </div>

                </div>


                <label>
                    Current Password
                </label>

                <input
                    type="password"
                    id="currentPassword"
                    placeholder="Current password"
                >


                <label>
                    New Password
                </label>

                <input
                    type="password"
                    id="newPassword"
                    placeholder="New password"
                >


                <label>
                    Confirm Password
                </label>

                <input
                    type="password"
                    id="confirmPassword"
                    placeholder="Confirm password"
                >


                <button
                    class="save-button"
                    id="changePassword">

                    <i class="fa-solid fa-key"></i>

                    Change Password

                </button>

            </div>


            <div class="settings-card">

                <div class="settings-title">

                    <i class="fa-solid fa-gears"></i>

                    <div>

                        <h3>Sorting Machine</h3>

                        <p>
                            Configure sorting system options.
                        </p>

                    </div>

                </div>


                <div class="setting-row">

                    <div>

                        <strong>
                            Automatic Sorting
                        </strong>

                        <small>
                            Enable automated classification
                        </small>

                    </div>

                    <label class="switch">

                        <input
                            type="checkbox"
                            checked
                            id="automaticSorting"
                        >

                        <span></span>

                    </label>

                </div>


                <div class="setting-row">

                    <div>

                        <strong>
                            Sound Notifications
                        </strong>

                        <small>
                            Play sound after sorting
                        </small>

                    </div>

                    <label class="switch">

                        <input
                            type="checkbox"
                            checked
                            id="soundNotification"
                        >

                        <span></span>

                    </label>

                </div>


                <div class="setting-row">

                    <div>

                        <strong>
                            Live Statistics
                        </strong>

                        <small>
                            Update dashboard automatically
                        </small>

                    </div>

                    <label class="switch">

                        <input
                            type="checkbox"
                            checked
                            id="liveStatistics"
                        >

                        <span></span>

                    </label>

                </div>

            </div>


            <div class="settings-card">

                <div class="settings-title">

                    <i class="fa-solid fa-circle-info"></i>

                    <div>

                        <h3>System Information</h3>

                        <p>
                            Information about this system.
                        </p>

                    </div>

                </div>


                <div class="system-information">

                    <div>
                        <span>System</span>
                        <strong>Egg Size Sorting System</strong>
                    </div>

                    <div>
                        <span>Farm</span>
                        <strong>YHAN & KIEL Fresh Farm Egg</strong>
                    </div>

                    <div>
                        <span>Version</span>
                        <strong>1.0.0</strong>
                    </div>

                    <div>
                        <span>Status</span>
                        <strong class="online-text">
                            ● Online
                        </strong>
                    </div>

                </div>

            </div>

        </div>

    </section>

</main>

<div
    class="notification-popup"
    id="notificationPopup">

    <i class="fa-solid fa-circle-check"></i>

    <span id="notificationText">
        Successfully saved.
    </span>

</div>




<script src="dashboard.js"></script>

</body>

</html>
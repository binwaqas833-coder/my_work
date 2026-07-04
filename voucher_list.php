<?php
session_start();
include 'login_signup.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$my_id = $_SESSION['user_id'];

// ————————————————————————————————————————————————————————————
// 1. AJAX OPERATIONS: FUTA VOCHA MOJA AU NYINGI (API & DB)
// ————————————————————————————————————————————————————————————
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    if ($action === 'delete') {
        $vid = (int)$_POST['id'];
        
        // Unganisha na RouterOS API kufuta vocha kwenye MikroTik kabla ya DB
        require_once 'routeros_api.class.php';
        require_once 'mikrotik_helper.php';     // helper mpya (mysqli version)

        $cfg = $conn->query("SELECT * FROM mikrotik_configs WHERE user_id='$my_id' LIMIT 1")->fetch_assoc();
        
        if ($cfg) {
            $API = new RouterosAPI();
            if ($API->connect($cfg['mikrotik_ip'], $cfg['api_user'], $cfg['api_pass'])) {
                // Tafuta jina au code ya vocha ili tuiondoe MikroTik
                $vc = $conn->query("SELECT code FROM vouchers WHERE id=$vid AND user_id='$my_id' LIMIT 1")->fetch_assoc();
                if ($vc) {
                    $API->comm('/ip/hotspot/user/remove', ['numbers' => $vc['code']]);
                }
                $API->disconnect();
            }
        }

        // Futa kwenye Database ya mfumo wako
        $d = $conn->prepare("DELETE FROM vouchers WHERE id=? AND user_id=?");
        $d->bind_param("ii", $vid, $my_id);
        $d->execute();
        echo json_encode(['status' => $d->affected_rows > 0 ? 'success' : 'error']);
        exit();
    }

    if ($action === 'delete_many') {
        $ids = array_map('intval', json_decode($_POST['ids'] ?? '[]', true));
        if (empty($ids)) {
            echo json_encode(['status' => 'error', 'msg' => 'Hakuna vocha zilizochaguliwa']);
            exit();
        }

        require_once 'routeros_api.class.php';
        $cfg = $conn->query("SELECT * FROM mikrotik_configs WHERE user_id='$my_id' LIMIT 1")->fetch_assoc();
        
        $API = null;
        if ($cfg) {
            $API = new RouterosAPI();
            if (!$API->connect($cfg['mikrotik_ip'], $cfg['api_user'], $cfg['api_pass'])) {
                $API = null;
            }
        }

        $deleted = 0;
        foreach ($ids as $vid) {
            if ($API) {
                $vc = $conn->query("SELECT code FROM vouchers WHERE id=$vid AND user_id='$my_id' LIMIT 1")->fetch_assoc();
                if ($vc) {
                    $API->comm('/ip/hotspot/user/remove', ['numbers' => $vc['code']]);
                }
            }
            
            // Futa kutoka kwenye database
            $d = $conn->prepare("DELETE FROM vouchers WHERE id=? AND user_id=?");
            $d->bind_param("ii", $vid, $my_id);
            $d->execute();
            if ($d->affected_rows > 0) {
                $deleted++;
            }
        }
        
        if ($API) {
            $API->disconnect();
        }

        echo json_encode(['status' => 'success', 'deleted' => $deleted]);
        exit();
    }
}

// ————————————————————————————————————————————————————————————
// 2. DATA QUERY & PAGINATION & FILTERING LOGIC
// ————————————————————————————————————————————————————————————
$total_all = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM vouchers WHERE user_id='$my_id'"))['c'] ?? 0;

$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';

$where_clause = "WHERE user_id='$my_id'";
if (!empty($search)) {
    $where_clause .= " AND (voucher_code LIKE '%$search%' OR mikrotik_profile LIKE '%$search%')";
}

// Pata jumla ya rekodi baada ya kufanya filter/search
$total_query = mysqli_query($conn, "SELECT COUNT(*) c FROM vouchers $where_clause");
$total_records = mysqli_fetch_assoc($total_query)['c'] ?? 0;
$total_pages = ceil($total_records / $limit);

// Leta data za kuonesha kwenye meza kulingana na ukurasa husika
$q = "SELECT * FROM vouchers $where_clause ORDER BY id DESC LIMIT $offset, $limit";
$res = mysqli_query($conn, $q);
?>
<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orodha ya Vocha</title>
</head>
<body>
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card my-4">
                <div class="card-header p-0 position-relative mt-n4 mx-3 z-index-2">
                    <div class="bg-gradient-dark shadow-dark border-radius-lg pt-4 pb-3 d-flex justify-content-between align-items-center">
                        <h6 class="text-white text-capitalize ps-3 mb-0">Orodha ya Vocha (<?php echo $total_records; ?>)</h6>
                        <button class="btn btn-danger btn-sm me-3 mb-0 d-none" id="btnDeleteMany" onclick="deleteSelected()">
                            <i class="material-icons text-sm">delete</i> Futa Zilizochaguliwa
                        </button>
                    </div>
                </div>
                
                <div class="card-body px-0 pb-2">
                    <div class="d-flex justify-content-between align-items-center mx-3 mb-3">
                        <div class="d-flex align-items-center">
                            <span class="text-sm me-2">Onyesha</span>
                            <select class="form-select form-select-sm" style="width: auto;" onchange="location = this.value;">
                                <option value="?limit=10&search=<?php echo urlencode($search); ?>" <?php echo $limit==10?'selected':''; ?>>10</option>
                                <option value="?limit=25&search=<?php echo urlencode($search); ?>" <?php echo $limit==25?'selected':''; ?>>25</option>
                                <option value="?limit=50&search=<?php echo urlencode($search); ?>" <?php echo $limit==50?'selected':''; ?>>50</option>
                                <option value="?limit=100&search=<?php echo urlencode($search); ?>" <?php echo $limit==10?'selected':''; ?>>100</option>
                            </select>
                        </div>
                        <div class="w-30">
                            <form method="GET">
                                <input type="hidden" name="limit" value="<?php echo $limit; ?>">
                                <div class="input-group input-group-outline <?php echo !empty($search) ? 'is-focused' : ''; ?>">
                                    <label class="form-label">Tafuta vocha...</label>
                                    <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2" style="width: 40px;">
                                        <div class="form-check p-0">
                                            <input class="form-check-input" type="checkbox" id="checkAll" onclick="toggleSelectAll(this)">
                                        </div>
                                    </th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Username / Code</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Password</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Profile</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Bei</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Hali</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Tarehe</th>
                                    <th class="text-secondary opacity-7"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(mysqli_num_rows($res) > 0): ?>
                                    <?php while($row = mysqli_fetch_assoc($res)): ?>
                                        <tr id="row_<?php echo $row['id']; ?>">
                                            <td>
                                                <div class="form-check p-0">
                                                    <input class="form-check-input voucher-checkbox" type="checkbox" value="<?php echo $row['id']; ?>" onclick="evaluateChecked()">
                                                </div>
                                            </td>
                                            <td>
                                                <p class="text-sm font-weight-bold mb-0 text-dark"><?php echo $row['voucher_code']; ?></p>
                                            </td>
                                            <td>
                                                <p class="text-sm mb-0 text-secondary"><?php echo !empty($row['password']) ? $row['password'] : '-'; ?></p>
                                            </td>
                                            <td>
                                                <p class="text-sm mb-0"><?php echo $row['mikrotik_profile']; ?></p>
                                            </td>
                                            <td>
                                                <p class="text-sm mb-0 font-weight-bold text-dark"><?php echo number_format($row['price']); ?> TZS</p>
                                            </td>
                                            <td>
                                                <span class="badge badge-sm <?php echo $row['status'] == 'used' ? 'bg-gradient-secondary' : 'bg-gradient-success'; ?>">
                                                    <?php echo $row['status'] == 'used' ? 'Imetumika' : 'Haijatumika'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <p class="text-xs mb-0 text-secondary"><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></p>
                                            </td>
                                            <td class="align-middle">
                                                <button class="btn btn-link text-danger text-gradient px-3 mb-0" onclick="deleteVoucher(<?php echo $row['id']; ?>)">
                                                    <i class="material-icons text-sm me-1">delete</i> Futa
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">
                                            <p class="text-sm text-secondary mb-0">Hakuna vocha zilizopatikana.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if($total_pages > 1): ?>
                        <div class="d-flex justify-content-center mt-4">
                            <nav aria-label="Page navigation">
                                <ul class="pagination pagination-dark mb-0">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=1&limit=<?php echo $limit; ?>&search=<?php echo urlencode($search); ?>">&laquo;</a>
                                    </li>
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page-1; ?>&limit=<?php echo $limit; ?>&search=<?php echo urlencode($search); ?>">Kabla</a>
                                    </li>
                                    
                                    <?php 
                                    $start = max(1, $page - 2);
                                    $end = min($total_pages, $page + 2);
                                    for($i = $start; $i <= $end; $i++): 
                                    ?>
                                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page+1; ?>&limit=<?php echo $limit; ?>&search=<?php echo urlencode($search); ?>">Baada</a>
                                    </li>
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $total_pages; ?>&limit=<?php echo $limit; ?>&search=<?php echo urlencode($search); ?>">&raquo;</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.voucher-checkbox');
    checkboxes.forEach(cb => cb.checked = master.checked);
    evaluateChecked();
}

function evaluateChecked() {
    const checkboxes = document.querySelectorAll('.voucher-checkbox:checked');
    const deleteBtn = document.getElementById('btnDeleteMany');
    if (checkboxes.length > 0) {
        deleteBtn.classList.remove('d-none');
    } else {
        deleteBtn.classList.add('d-none');
        document.getElementById('checkAll').checked = false;
    }
}

function deleteVoucher(id) {
    if (confirm("Je, una uhakika unataka kufuta vocha hii? Kitendo hiki kitaifuta pia kwenye MikroTik router.")) {
        const formData = new FormData();
        formData.append('ajax_action', 'delete');
        formData.append('id', id);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                const row = document.getElementById('row_' + id);
                if(row) row.remove();
                alert('Vocha imefutwa kwa mafanikio kutoka kwenye database na MikroTik!');
                location.reload(); // Hiari: Kurefresh ukurasa ili hesabu za data zijirekebishe upya
            } else {
                alert('Imeshindikana kufuta vocha. Tafadhali angalia muunganisho wa Router wako.');
            }
        })
        .catch(err => console.error('Error:', err));
    }
}

function deleteSelected() {
    const checkedBoxes = document.querySelectorAll('.voucher-checkbox:checked');
    const ids = Array.from(checkedBoxes).map(cb => parseInt(cb.value));

    if (ids.length === 0) return;

    if (confirm(`Je, una uhakika unataka kufuta vocha zote zilizochaguliwa (${ids.length})? Zitaondolewa pia kwenye MikroTik.`)) {
        const formData = new FormData();
        formData.append('ajax_action', 'delete_many');
        formData.append('ids', JSON.stringify(ids));

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                ids.forEach(id => {
                    const row = document.getElementById('row_' + id);
                    if (row) row.remove();
                });
                document.getElementById('btnDeleteMany').classList.add('d-none');
                document.getElementById('checkAll').checked = false;
                alert(`Imekamilika vizuri! Vocha ${data.deleted} zimefutwa.`);
                location.reload(); // Kurefresh ili pagination isomeke vizuri upya
            } else {
                alert('Kuna tatizo limejitokeza wakati wa kufuta kwa pamoja: ' + (data.msg || ''));
            }
        })
        .catch(err => console.error('Error:', err));
    }
}
</script>
</body>
</html>